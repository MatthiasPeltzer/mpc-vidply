<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Player;

use Mpc\MpcVidply\Enums\MediaMimeType;
use Mpc\MpcVidply\Enums\MediaType;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * For single-item players, lifts the first track into the template-level
 * variables (sources, poster, text tracks, accessibility files, download) and
 * the player options that depend on them.
 *
 * @phpstan-import-type TrackResult from TrackAssembler
 */
final class SingleTrackDataExtractor
{
    private const EMPTY_TRACK_DATA = [
        'videoUrl' => '',
        'poster' => null,
        'mediaFiles' => [],
        'sources' => null,
        'captions' => [],
        'chapters' => [],
        'audioDescriptionTracks' => [],
        'signLanguage' => [],
    ];

    private const EXTERNAL_TYPES = [MediaType::YouTube, MediaType::Vimeo, MediaType::SoundCloud];

    private const METADATA_KEYS = ['title', 'artist', 'description', 'longDescription', 'date'];

    private readonly UrlSanitizer $urlSanitizer;
    private readonly DownloadResolver $downloadResolver;

    public function __construct(
        ?UrlSanitizer $urlSanitizer = null,
        ?DownloadResolver $downloadResolver = null
    ) {
        $this->urlSanitizer = $urlSanitizer ?? GeneralUtility::makeInstance(UrlSanitizer::class);
        $this->downloadResolver = $downloadResolver ?? GeneralUtility::makeInstance(DownloadResolver::class);
    }

    /**
     * @param TrackResult $trackResult
     * @return array{trackData: array<string, mixed>, optionOverrides: array<string, mixed>}
     */
    public function extract(array $trackResult, string $siteDefaultLanguageCode = 'en'): array
    {
        if ($trackResult['isPlaylist'] || $trackResult['tracks'] === []) {
            return ['trackData' => self::EMPTY_TRACK_DATA, 'optionOverrides' => []];
        }

        $firstTrack = $trackResult['tracks'][0];
        $result = array_replace(self::EMPTY_TRACK_DATA, $this->resolveSourceData($firstTrack));
        $optionOverrides = [];

        if (!empty($firstTrack['poster'])) {
            $safePoster = $this->urlSanitizer->sanitizeForCssUrl((string)$firstTrack['poster']);
            if ($safePoster !== null) {
                $result['poster'] = $safePoster;
                $optionOverrides['poster'] = $safePoster;
            }
        }

        if (!empty($firstTrack['duration'])) {
            $optionOverrides['initialDuration'] = (int)$firstTrack['duration'];
        }

        $result = array_replace($result, $this->resolveTextTracks($firstTrack));

        $accessibility = $this->resolveAccessibilityOverrides($firstTrack, $siteDefaultLanguageCode);
        $result['audioDescriptionTracks'] = $accessibility['audioDescriptionTracks'];
        $result['signLanguage'] = $accessibility['signLanguage'];
        $optionOverrides = array_merge($optionOverrides, $accessibility['optionOverrides']);

        if (!empty($firstTrack['allowDownload'])) {
            $downloadUrl = $this->downloadResolver->resolveUrl($firstTrack);
            if ($downloadUrl !== null) {
                $result['downloadUrl'] = $downloadUrl;
                $optionOverrides['downloadButton'] = true;
            }
        }

        foreach (self::METADATA_KEYS as $metadataKey) {
            $value = $firstTrack[$metadataKey] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $optionOverrides[$metadataKey] = $value;
            }
        }

        return ['trackData' => $result, 'optionOverrides' => $optionOverrides];
    }

    /**
     * Decide whether the track is handed over as `data-vidply-src`, as a list of
     * `<source>` children or as a single media file.
     *
     * @param array<string, mixed> $track
     * @return array{videoUrl?: string, sources?: mixed, mediaFiles?: list<array<string, mixed>>}
     */
    private function resolveSourceData(array $track): array
    {
        $type = MediaType::tryFrom($track['type'] ?? '');
        $mimeType = strtolower((string)($track['type'] ?? ''));
        $hasMultipleSources = !empty($track['sources']) && count($track['sources']) > 1;
        $isSingleMseSource = !$hasMultipleSources && MediaMimeType::isStreaming($mimeType);

        if ($type !== null && in_array($type, self::EXTERNAL_TYPES, true)) {
            return ['videoUrl' => $track['src']];
        }
        if ($isSingleMseSource) {
            // Firefox fires a "may not load data from blob:" security warning when a
            // <video> element first tries to preload an unplayable <source> (a DASH
            // or HLS manifest) and is then handed a blob: URL by dash.js / hls.js via
            // MediaSource Extensions. Routing the manifest through data-vidply-src
            // (instead of a <source> child) avoids rendering the unplayable source in
            // the first place — the MSE renderers pick the URL up from the attribute.
            // See Mozilla bug 1768052 and moonfire-nvr issue #286 for the upstream
            // Firefox behaviour.
            return ['videoUrl' => $track['src']];
        }

        $mseSourceUrl = $this->findStreamingSourceUrl($track);
        if ($mseSourceUrl !== null) {
            // Multi-source track that contains a DASH/HLS manifest alongside native
            // fallbacks (e.g. an MP4). dash.js / hls.js take over playback and strip
            // all <source> children on init, so rendering the native fallbacks is
            // pointless and triggers the same Firefox preload/blob race described
            // above. Hand the manifest URL to the MSE renderer via data-vidply-src
            // and suppress the <source> list entirely.
            return ['videoUrl' => $mseSourceUrl];
        }
        if ($hasMultipleSources) {
            return ['sources' => $track['sources']];
        }

        return ['mediaFiles' => [[
            'publicUrl' => $track['src'],
            'mimeType' => $track['type'],
            'label' => 'Default',
            'properties' => [],
        ]]];
    }

    /**
     * @param array<string, mixed> $track
     */
    private function findStreamingSourceUrl(array $track): mixed
    {
        foreach ($track['sources'] ?? [] as $source) {
            $sourceMime = strtolower((string)($source['type'] ?? ''));
            if (MediaMimeType::isStreaming($sourceMime)) {
                return $source['src'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $track
     * @return array{captions: list<mixed>, chapters: list<mixed>}
     */
    private function resolveTextTracks(array $track): array
    {
        $captions = [];
        $chapters = [];
        foreach ($track['tracks'] ?? [] as $textTrack) {
            if ($textTrack['kind'] === 'chapters') {
                $chapters[] = $textTrack;
            } else {
                $captions[] = $textTrack;
            }
        }

        return ['captions' => $captions, 'chapters' => $chapters];
    }

    /**
     * @param array<string, mixed> $track
     * @return array{
     *     audioDescriptionTracks: list<array<string, mixed>>,
     *     signLanguage: list<array<string, mixed>>,
     *     optionOverrides: array<string, mixed>
     * }
     */
    private function resolveAccessibilityOverrides(array $track, string $siteDefaultLanguageCode): array
    {
        $audioDescriptionTracks = [];
        $signLanguage = [];
        $optionOverrides = [];

        if (!empty($track['audioDescriptionSrc'])) {
            $audioDescriptionTracks[] = [
                'src' => $track['audioDescriptionSrc'],
                'lang' => $siteDefaultLanguageCode,
                'label' => 'Audio Description',
                'mimeType' => '',
            ];
            $optionOverrides['audioDescriptionSrc'] = $track['audioDescriptionSrc'];
            $optionOverrides['audioDescriptionButton'] = true;
        }

        $audioDescriptionMode = (string)($track['audioDescriptionMode'] ?? 'auto');
        if (in_array($audioDescriptionMode, ['auto', 'swap', 'vtt_speech'], true)) {
            $optionOverrides['audioDescriptionMode'] = $audioDescriptionMode;
        }

        if (!empty($track['audioDescriptionSrc']) || $this->hasDescriptionsTrack($track)) {
            $optionOverrides['audioDescriptionButton'] = true;
        }

        if (!empty($track['signLanguageSrc'])) {
            $signLanguage[] = [
                'src' => $track['signLanguageSrc'],
                'lang' => $siteDefaultLanguageCode,
                'label' => 'Sign Language',
            ];
            $optionOverrides['signLanguageSrc'] = $track['signLanguageSrc'];
            $optionOverrides['signLanguageButton'] = true;
            $optionOverrides['signLanguagePosition'] = 'bottom-right';
            $displayMode = $track['signLanguageDisplayMode'] ?? 'pip';
            $optionOverrides['signLanguageDisplayMode'] = in_array($displayMode, ['pip', 'main', 'both'], true) ? $displayMode : 'pip';
        }

        return [
            'audioDescriptionTracks' => $audioDescriptionTracks,
            'signLanguage' => $signLanguage,
            'optionOverrides' => $optionOverrides,
        ];
    }

    /**
     * @param array<string, mixed> $track
     */
    private function hasDescriptionsTrack(array $track): bool
    {
        foreach ($track['tracks'] ?? [] as $textTrack) {
            if (($textTrack['kind'] ?? '') === 'descriptions') {
                return true;
            }
        }

        return false;
    }
}
