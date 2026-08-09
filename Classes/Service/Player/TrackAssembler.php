<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Player;

use Mpc\MpcVidply\Enums\MediaMimeType;
use Mpc\MpcVidply\Enums\MediaType;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Turns media records into the track array the player is handed, including the
 * accessibility files (captions, chapters, audio description, sign language)
 * and the download each track offers.
 *
 * @phpstan-type TrackResult array{
 *     tracks: list<array<string, mixed>>,
 *     records: list<array<string, mixed>>,
 *     mediaType: ?string,
 *     hasExternalMedia: bool,
 *     hasLocalMedia: bool,
 *     externalServiceTypes: list<string>,
 *     isPlaylist: bool,
 *     isMixedPlaylist: bool
 * }
 */
final class TrackAssembler
{
    /** Every file relation a rendered player can draw on. */
    public const MEDIA_FIELDS = [
        'media_file',
        'poster',
        'captions',
        'chapters',
        'audio_description',
        'sign_language',
    ];

    /**
     * Allow-listed kind values for <track> elements.
     * Used to reject database values that would confuse the player or AT.
     */
    private const ALLOWED_TRACK_KINDS = ['captions', 'subtitles', 'descriptions', 'chapters', 'metadata'];

    private readonly MediaFileRegistry $files;
    private readonly UrlSanitizer $urlSanitizer;
    private readonly DownloadResolver $downloadResolver;
    private readonly LocaleFormatter $localeFormatter;

    public function __construct(
        ?MediaFileRegistry $files = null,
        ?UrlSanitizer $urlSanitizer = null,
        ?DownloadResolver $downloadResolver = null,
        ?LocaleFormatter $localeFormatter = null
    ) {
        $this->files = $files ?? GeneralUtility::makeInstance(MediaFileRegistry::class);
        $this->urlSanitizer = $urlSanitizer ?? GeneralUtility::makeInstance(UrlSanitizer::class);
        $this->downloadResolver = $downloadResolver ?? GeneralUtility::makeInstance(DownloadResolver::class);
        $this->localeFormatter = $localeFormatter ?? GeneralUtility::makeInstance(LocaleFormatter::class);
    }

    /**
     * Build every playable track of an assembly, loading the file relations of
     * all records up front.
     *
     * @param list<array<string, mixed>> $mediaRecords
     * @return TrackResult
     */
    public function assemble(array $mediaRecords, string $siteDefaultLanguageCode = 'en', ?string $locale = null): array
    {
        $this->files->prefetch($this->collectMediaUids($mediaRecords), self::MEDIA_FIELDS);

        $tracks = [];
        $records = [];
        $mediaType = null;
        $externalServiceTypes = [];
        $hasLocalMedia = false;
        $hasExternalMedia = false;

        foreach ($mediaRecords as $mediaRecord) {
            $track = $this->buildTrack($mediaRecord, $siteDefaultLanguageCode, $locale);
            if ($track === null) {
                continue;
            }
            $tracks[] = $track;
            // Records without a resolvable source are dropped above. Keeping the
            // surviving ones lets the episode list use the same indexes as the
            // playlist, so its play buttons cannot point at the wrong track.
            $records[] = $mediaRecord;

            if ($mediaType === null) {
                $mediaType = (($mediaRecord['media_type'] ?? '') === MediaType::Audio->value) ? 'audio' : 'video';
            }

            $recordType = MediaType::tryFrom((string)($mediaRecord['media_type'] ?? ''));
            if ($recordType !== null && $recordType->isExternal()) {
                $hasExternalMedia = true;
                if (!in_array($recordType->value, $externalServiceTypes, true)) {
                    $externalServiceTypes[] = $recordType->value;
                }
            } else {
                $hasLocalMedia = true;
            }
        }

        $isPlaylist = count($tracks) > 1;

        return [
            'tracks' => $tracks,
            'records' => $records,
            'mediaType' => $mediaType,
            'hasExternalMedia' => $hasExternalMedia,
            'hasLocalMedia' => $hasLocalMedia,
            'externalServiceTypes' => $externalServiceTypes,
            'isPlaylist' => $isPlaylist,
            'isMixedPlaylist' => $isPlaylist && $hasExternalMedia && $hasLocalMedia,
        ];
    }

    /**
     * @param array<string, mixed> $mediaRecord
     * @return array<string, mixed>|null
     */
    public function buildTrack(array $mediaRecord, string $siteDefaultLanguageCode = 'en', ?string $locale = null): ?array
    {
        $mediaType = MediaType::tryFrom((string)($mediaRecord['media_type'] ?? ''));
        if ($mediaType === null) {
            return null;
        }

        $mediaUid = (int)$mediaRecord['uid'];
        $sourceData = $this->resolveSource($mediaUid, $mediaType);
        if ($sourceData === null) {
            return null;
        }

        $track = array_merge($this->buildBaseTrackData($mediaRecord, $locale), $sourceData);
        $this->enrichTrackWithAccessibilityData($track, $mediaUid, $mediaRecord, $siteDefaultLanguageCode);
        $this->downloadResolver->enrichTrack($track, $mediaUid);

        return $track;
    }

    /**
     * Track data for a structured-data (JSON-LD) node: base metadata plus the
     * resolved source, skipping the caption, chapter, sign-language and download
     * enrichment a rendered player needs.
     *
     * @param array<string, mixed> $mediaRecord
     * @return array<string, mixed>|null
     */
    public function assembleStructuredDataTrack(array $mediaRecord, ?string $locale = null): ?array
    {
        $mediaUid = (int)($mediaRecord['uid'] ?? 0);
        $mediaType = MediaType::tryFrom((string)($mediaRecord['media_type'] ?? ''));
        if ($mediaUid <= 0 || $mediaType === null) {
            return null;
        }

        // Only media_file references are required to resolve content/embed URLs.
        $this->files->prefetch([$mediaUid], ['media_file']);

        $sourceData = $this->resolveSource($mediaUid, $mediaType);
        if ($sourceData === null) {
            return null;
        }

        return array_merge($this->buildBaseTrackData($mediaRecord, $locale), $sourceData);
    }

    /**
     * Source URL(s) of a media record, without any of the accessibility or
     * download enrichment a rendered player needs.
     *
     * @return array{src: string, type: string, kind: string, sources?: list<array<string, mixed>>}|null
     */
    public function resolveSource(int $mediaUid, MediaType $mediaType): ?array
    {
        return match ($mediaType) {
            MediaType::YouTube, MediaType::Vimeo => $this->resolveEmbedSource($mediaUid, $mediaType),
            MediaType::SoundCloud => $this->resolveSoundCloudSource($mediaUid),
            MediaType::Video, MediaType::Audio => $this->resolveLocalMediaSource($mediaUid, $mediaType),
        };
    }

    /**
     * @param array<string, mixed> $mediaRecord
     * @return array<string, mixed>
     */
    public function buildBaseTrackData(array $mediaRecord, ?string $locale = null): array
    {
        $track = [
            'title' => $mediaRecord['title'] ?: 'Untitled',
        ];

        if (!empty($mediaRecord['hide_speed_button'])) {
            $track['hideSpeedButton'] = true;
        }
        if (!empty($mediaRecord['hide_help_button'])) {
            $track['hideHelpButton'] = true;
        }
        if (!empty($mediaRecord['allow_download'])) {
            $track['allowDownload'] = true;
        }
        if (!empty($mediaRecord['artist'])) {
            $track['artist'] = $mediaRecord['artist'];
        }
        if (!empty($mediaRecord['duration'])) {
            $track['duration'] = (int)$mediaRecord['duration'];
        }
        if (!empty($mediaRecord['audio_description_duration'])) {
            $track['audioDescriptionDuration'] = (int)$mediaRecord['audio_description_duration'];
        }
        $audioDescriptionMode = (string)($mediaRecord['audio_description_mode'] ?? 'auto');
        if (in_array($audioDescriptionMode, ['auto', 'swap', 'vtt_speech'], true)) {
            $track['audioDescriptionMode'] = $audioDescriptionMode;
        }
        if (!empty($mediaRecord['description'])) {
            $track['description'] = $mediaRecord['description'];
        }
        // The player renders the date verbatim — it has no locale knowledge.
        $publishDate = $this->localeFormatter->formatDate((int)($mediaRecord['publish_date'] ?? 0), $locale);
        if ($publishDate !== '') {
            $track['date'] = $publishDate;
        }
        $episodeNumber = trim((string)($mediaRecord['episode_number'] ?? ''));
        if ($episodeNumber !== '') {
            $track['episodeNumber'] = $episodeNumber;
        }

        return $track;
    }

    /**
     * @param list<array<string, mixed>> $mediaRecords
     * @return list<int>
     */
    private function collectMediaUids(array $mediaRecords): array
    {
        $mediaUids = array_map(
            static fn (array $row): int => (int)($row['uid'] ?? 0),
            $mediaRecords
        );

        return array_values(array_unique(array_filter($mediaUids, static fn (int $uid): bool => $uid > 0)));
    }

    /** @return array{src: string, type: string, kind: string}|null */
    private function resolveEmbedSource(int $mediaUid, MediaType $mediaType): ?array
    {
        $mediaFiles = $this->files->getReferences($mediaUid, 'media_file');
        if ($mediaFiles === []) {
            return null;
        }
        $src = $this->files->getPublicUrl($mediaFiles[0]);
        if ($src === '') {
            return null;
        }

        return [
            'src' => $src,
            'type' => $mediaType->value,
            'kind' => 'video',
        ];
    }

    /** @return array{src: string, type: string, kind: string}|null */
    private function resolveSoundCloudSource(int $mediaUid): ?array
    {
        $mediaFiles = $this->files->getReferences($mediaUid, 'media_file');
        if ($mediaFiles === []) {
            return null;
        }

        return [
            'src' => $this->files->getPublicUrl($mediaFiles[0]),
            'type' => MediaType::SoundCloud->value,
            'kind' => 'audio',
        ];
    }

    /** @return array{src: string, type: string, kind: string, sources?: list<array<string, mixed>>}|null */
    private function resolveLocalMediaSource(int $mediaUid, MediaType $mediaType): ?array
    {
        $mediaFiles = $this->files->getReferences($mediaUid, 'media_file');
        if ($mediaFiles === []) {
            return null;
        }

        if (count($mediaFiles) === 1) {
            $mediaFile = $mediaFiles[0];
            $src = $this->files->getPublicUrl($mediaFile);
            $type = $this->files->getMimeType($mediaFile);
            if (in_array($mediaFile->getExtension(), MediaMimeType::NON_PROGRESSIVE_EXTENSIONS, true)) {
                $type = $this->files->inferMimeTypeFromUrl($src, $type);
            }

            return ['src' => $src, 'type' => $type, 'kind' => $mediaType->value];
        }

        $sources = [];
        foreach ($mediaFiles as $mediaFile) {
            $publicUrl = $this->files->getPublicUrl($mediaFile);
            $mimeType = $this->files->getMimeType($mediaFile);
            if (in_array($mediaFile->getExtension(), MediaMimeType::NON_PROGRESSIVE_EXTENSIONS, true)) {
                $mimeType = $this->files->inferMimeTypeFromUrl($publicUrl, $mimeType);
            }
            $sources[] = [
                'src' => $publicUrl,
                'type' => $mimeType,
                'label' => 'Default',
            ];
        }

        // MSE streams first: the player picks the first source it can play, and
        // an adaptive manifest beats the progressive fallback behind it.
        usort($sources, static function (array $a, array $b): int {
            $priority = static function (string $type): int {
                return match (true) {
                    MediaMimeType::isDash($type) => 0,
                    MediaMimeType::isHls($type) => 1,
                    default => 2,
                };
            };

            return $priority($a['type']) <=> $priority($b['type']);
        });

        return [
            'src' => $sources[0]['src'],
            'type' => $sources[0]['type'],
            'kind' => $mediaType->value,
            'sources' => $sources,
        ];
    }

    /**
     * @param array<string, mixed> $track
     * @param array<string, mixed> $mediaRecord
     */
    private function enrichTrackWithAccessibilityData(
        array &$track,
        int $mediaUid,
        array $mediaRecord,
        string $siteDefaultLanguageCode = 'en'
    ): void {
        $posterFiles = $this->files->getReferences($mediaUid, 'poster');
        if ($posterFiles !== []) {
            $safePosterUrl = $this->urlSanitizer->sanitizeForCssUrl($this->files->getPublicUrl($posterFiles[0]));
            if ($safePosterUrl !== null) {
                $track['poster'] = $safePosterUrl;
            }
        }

        $textTracks = array_merge(
            $this->buildTextTracks($mediaUid, 'captions', null, $siteDefaultLanguageCode),
            $this->buildTextTracks($mediaUid, 'chapters', 'chapters', $siteDefaultLanguageCode)
        );
        if ($textTracks !== []) {
            $track['tracks'] = $textTracks;
        }

        $audioDescFiles = $this->files->getReferences($mediaUid, 'audio_description');
        if ($audioDescFiles !== []) {
            $audioDescUrl = $this->files->getPublicUrl($audioDescFiles[0]);
            if ($audioDescUrl !== '') {
                $track['audioDescriptionSrc'] = $audioDescUrl;
            }
        }

        $signLangFiles = $this->files->getReferences($mediaUid, 'sign_language');
        if ($signLangFiles !== []) {
            $signLangUrl = $this->files->getPublicUrl($signLangFiles[0]);
            if ($signLangUrl !== '') {
                $track['signLanguageSrc'] = $signLangUrl;
                $track['signLanguageDisplayMode'] = $mediaRecord['sign_language_display_mode'] ?? 'pip';
            }
        }

        if (!empty($mediaRecord['enable_transcript'])) {
            $track['enableTranscript'] = true;
        }
    }

    /**
     * @param ?string $forcedKind Kind every track of this field has, or null to
     *                            take it from the file metadata.
     * @return list<array<string, mixed>>
     */
    private function buildTextTracks(int $mediaUid, string $fieldName, ?string $forcedKind, string $fallbackLanguageCode): array
    {
        $textTracks = [];

        foreach ($this->files->getReferences($mediaUid, $fieldName) as $fileReference) {
            $url = $this->files->getPublicUrl($fileReference);
            if ($url === '') {
                continue;
            }

            $properties = $fileReference->getProperties();
            $trackData = $this->sanitizeTextTrack([
                'src' => $url,
                'kind' => $forcedKind ?? (string)($properties['tx_track_kind'] ?? ''),
                'srclang' => (string)($properties['tx_lang_code'] ?? ''),
                'label' => (string)($properties['title'] ?? ''),
            ], $fallbackLanguageCode);

            $describedSourceUrl = $this->files->getDescribedSourceUrl($fileReference);
            if ($describedSourceUrl !== null) {
                $trackData['describedSrc'] = $describedSourceUrl;
            }

            $textTracks[] = $trackData;
        }

        return $textTracks;
    }

    /**
     * @param array<string, mixed> $textTrack
     * @return array<string, mixed>
     */
    private function sanitizeTextTrack(array $textTrack, string $fallbackLanguageCode): array
    {
        $kind = strtolower((string)($textTrack['kind'] ?? ''));
        if (!in_array($kind, self::ALLOWED_TRACK_KINDS, true)) {
            $kind = 'captions';
        }
        $textTrack['kind'] = $kind;

        $srclang = trim((string)($textTrack['srclang'] ?? ''));
        if ($srclang === '') {
            $srclang = $fallbackLanguageCode;
        }
        $textTrack['srclang'] = $srclang;

        $label = $this->urlSanitizer->stripControlChars((string)($textTrack['label'] ?? ''));
        if ($label === '') {
            $label = $kind === 'chapters' ? 'Chapters' : ($kind === 'descriptions' ? 'Descriptions' : 'Captions');
        }
        if (mb_strlen($label) > 255) {
            $label = mb_substr($label, 0, 255);
        }
        $textTrack['label'] = $label;

        return $textTrack;
    }
}
