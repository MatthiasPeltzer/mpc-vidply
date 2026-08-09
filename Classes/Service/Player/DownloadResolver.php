<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Player;

use Mpc\MpcVidply\Enums\MediaMimeType;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Picks the file a download should point at and describes it the way the
 * player's own download button does, so a track and its episode row can never
 * announce two different formats or sizes for the same medium.
 */
final class DownloadResolver
{
    /**
     * Sources that can be saved as a file. A manifest describes a stream and is
     * useless on disk, so it is never offered even when it is the only source.
     */
    private const PROGRESSIVE_TYPES = ['video/mp4', 'video/webm', 'audio/mpeg', 'audio/ogg'];

    /**
     * Format labels for download links, kept in sync with the player's own
     * download button (see vidply's utils/DownloadInfo.ts).
     */
    private const FORMAT_BY_MIME = [
        'video/mp4' => 'MP4',
        'video/webm' => 'WebM',
        'video/ogg' => 'Ogg',
        'video/quicktime' => 'MOV',
        'audio/mpeg' => 'MP3',
        'audio/mp3' => 'MP3',
        'audio/mp4' => 'M4A',
        'audio/x-m4a' => 'M4A',
        'audio/aac' => 'AAC',
        'audio/ogg' => 'Ogg',
        'audio/opus' => 'Opus',
        'audio/wav' => 'WAV',
        'audio/x-wav' => 'WAV',
        'audio/flac' => 'FLAC',
        'audio/x-flac' => 'FLAC',
        'audio/webm' => 'WebM',
    ];

    private readonly MediaFileRegistry $files;
    private readonly LocaleFormatter $localeFormatter;

    public function __construct(
        ?MediaFileRegistry $files = null,
        ?LocaleFormatter $localeFormatter = null
    ) {
        $this->files = $files ?? GeneralUtility::makeInstance(MediaFileRegistry::class);
        $this->localeFormatter = $localeFormatter ?? GeneralUtility::makeInstance(LocaleFormatter::class);
    }

    /**
     * @param array<string, mixed> $track
     */
    public function resolveUrl(array $track): ?string
    {
        $source = $this->resolveSource($track);

        return $source !== null ? $source['src'] : null;
    }

    /**
     * Download target of a track, so the player's button can follow the selected
     * one in a playlist. Format and size travel with it because the button would
     * otherwise have to measure the file with a HEAD request — and could then
     * announce a different size than the episode list on the same page.
     *
     * @param array<string, mixed> $track
     */
    public function enrichTrack(array &$track, int $mediaUid): void
    {
        if (empty($track['allowDownload'])) {
            return;
        }

        $source = $this->resolveSource($track);
        if ($source === null) {
            return;
        }

        $track['downloadUrl'] = $source['src'];

        $format = $this->resolveFormat($source['src'], $source['type']);
        if ($format !== '') {
            $track['downloadFormat'] = $format;
        }

        $sizeBytes = $this->resolveSizeBytes($mediaUid, $source['src']);
        if ($sizeBytes > 0) {
            $track['downloadFileSize'] = $sizeBytes;
        }
    }

    /**
     * Download target of a single episode row, together with a ready-made
     * "MP3, 7.4 MB" hint. Returns empty values when the record does not allow
     * downloads or no progressive source can be offered.
     *
     * @param array<string, mixed> $mediaRecord
     * @param array<string, mixed>|null $track
     * @return array{url: string, info: string}
     */
    public function describeForEpisode(array $mediaRecord, ?array $track, ?string $locale): array
    {
        $empty = ['url' => '', 'info' => ''];

        if ($track === null || empty($mediaRecord['allow_download'])) {
            return $empty;
        }

        $source = $this->resolveSource($track);
        if ($source === null) {
            return $empty;
        }

        $parts = [];
        $format = $this->resolveFormat($source['src'], $source['type']);
        if ($format !== '') {
            $parts[] = $format;
        }
        $size = $this->localeFormatter->formatFileSize(
            $this->resolveSizeBytes((int)($mediaRecord['uid'] ?? 0), $source['src']),
            $locale
        );
        if ($size !== '') {
            $parts[] = $size;
        }

        return ['url' => $source['src'], 'info' => implode(', ', $parts)];
    }

    /**
     * The source a download should point at, with the MIME type it was
     * announced with so a format label can be derived from it.
     *
     * @param array<string, mixed> $track
     * @return array{src: string, type: string}|null
     */
    private function resolveSource(array $track): ?array
    {
        // Prefer a progressive source from multi-source tracks
        if (!empty($track['sources'])) {
            foreach ($track['sources'] as $source) {
                if (in_array($source['type'] ?? '', self::PROGRESSIVE_TYPES, true)) {
                    return [
                        'src' => (string)$source['src'],
                        'type' => (string)$source['type'],
                    ];
                }
            }
        }

        $src = (string)($track['src'] ?? '');

        return $src !== '' ? ['src' => $src, 'type' => (string)($track['type'] ?? '')] : null;
    }

    /**
     * Human-readable format label ("MP3", "MP4", …) for a download, matching the
     * labels the player's own download button prints.
     */
    private function resolveFormat(string $url, string $mimeType): string
    {
        $normalizedMime = strtolower(trim(explode(';', $mimeType)[0]));
        if (isset(self::FORMAT_BY_MIME[$normalizedMime])) {
            return self::FORMAT_BY_MIME[$normalizedMime];
        }

        $path = (string)parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{2,5}$/', $extension) === 1 ? strtoupper($extension) : '';
    }

    /**
     * Byte size of the file a download points at, or 0 when it cannot be
     * measured. Streaming and "external URL" placeholder files count as
     * unmeasurable: their own size says nothing about the media behind them.
     */
    private function resolveSizeBytes(int $mediaUid, string $downloadUrl): int
    {
        foreach ($this->files->getReferences($mediaUid, 'media_file') as $fileReference) {
            if ($this->files->getPublicUrl($fileReference) !== $downloadUrl) {
                continue;
            }
            if (in_array($fileReference->getExtension(), MediaMimeType::NON_PROGRESSIVE_EXTENSIONS, true)) {
                return 0;
            }

            return $this->files->getCurrentFileSize($fileReference);
        }

        return 0;
    }
}
