<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves import metadata from oEmbed and FAL file properties.
 *
 * TYPO3's {@see OnlineMediaHelperInterface::getMetaData()} omits title when the
 * online-media file already has a title property — we merge oEmbed + file data.
 */
final class MediaOEmbedMetadataService
{
    /**
     * @return array{
     *     title: string,
     *     artist: string,
     *     description: string,
     *     duration: int,
     *     thumbnailUrl: string,
     * }
     */
    public function resolveForFile(File $file, ?OnlineMediaHelperInterface $helper): array
    {
        $helperMetadata = $helper instanceof OnlineMediaHelperInterface
            ? $helper->getMetaData($file)
            : [];

        $title = $this->normalizeText((string)($helperMetadata['title'] ?? ''));
        $artist = $this->normalizeText((string)($helperMetadata['author'] ?? ''));

        if ($title === '') {
            $title = $this->resolveTitleFromFile($file);
        }

        $oEmbed = [];
        if ($helper instanceof OnlineMediaHelperInterface && $this->supportsOEmbed($file)) {
            $oEmbed = $this->fetchOEmbed($file, $helper);
            if ($title === '') {
                $title = $this->normalizeText((string)($oEmbed['title'] ?? ''));
            }
            if ($artist === '') {
                $artist = $this->normalizeText((string)($oEmbed['author_name'] ?? ''));
            }
        }

        $description = $this->normalizeText((string)($oEmbed['description'] ?? ''));
        $duration = $this->normalizeDuration($oEmbed['duration'] ?? null);
        $thumbnailUrl = trim((string)($oEmbed['thumbnail_url'] ?? ''));

        if ($file->getExtension() === 'youtube' && $helper instanceof OnlineMediaHelperInterface) {
            $videoId = trim((string)$helper->getOnlineMediaId($file));
            if ($videoId !== '') {
                $pageDetails = $this->fetchYouTubePageDetails($videoId);
                if ($description === '' && $pageDetails['description'] !== '') {
                    $description = $pageDetails['description'];
                }
                if ($duration === 0 && $pageDetails['duration'] > 0) {
                    $duration = $pageDetails['duration'];
                }
                if ($thumbnailUrl === '') {
                    $thumbnailUrl = sprintf(
                        'https://i.ytimg.com/vi/%s/hqdefault.jpg',
                        rawurlencode($videoId)
                    );
                }
            }
        }

        if ($file->getExtension() === 'soundcloud' && $helper instanceof OnlineMediaHelperInterface) {
            $trackUrl = trim((string)$helper->getOnlineMediaId($file));
            if ($trackUrl !== '' && $this->isSoundCloudTrackUrl($trackUrl)) {
                $pageDetails = $this->fetchSoundCloudPageDetails($trackUrl);
                if ($description === '' && $pageDetails['description'] !== '') {
                    $description = $pageDetails['description'];
                }
                if ($duration === 0 && $pageDetails['duration'] > 0) {
                    $duration = $pageDetails['duration'];
                }
            }
        }

        return [
            'title' => $title,
            'artist' => $artist,
            'description' => $description,
            'duration' => $duration,
            'thumbnailUrl' => $thumbnailUrl,
        ];
    }

    private function supportsOEmbed(File $file): bool
    {
        return in_array($file->getExtension(), ['youtube', 'vimeo', 'soundcloud'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOEmbed(File $file, OnlineMediaHelperInterface $helper): array
    {
        $mediaId = trim((string)$helper->getOnlineMediaId($file));
        if ($mediaId === '') {
            return [];
        }

        $oEmbedUrl = match ($file->getExtension()) {
            'youtube' => sprintf(
                'https://www.youtube.com/oembed?url=%s&format=json&maxwidth=2048&maxheight=2048',
                rawurlencode('https://www.youtube.com/watch?v=' . rawurlencode($mediaId))
            ),
            'vimeo' => sprintf(
                'https://vimeo.com/api/oembed.json?url=%s',
                rawurlencode('https://vimeo.com/' . rawurlencode($mediaId))
            ),
            'soundcloud' => sprintf(
                'https://soundcloud.com/oembed?format=json&url=%s',
                rawurlencode($mediaId)
            ),
            default => null,
        };

        if ($oEmbedUrl === null) {
            return [];
        }

        $response = GeneralUtility::getUrl($oEmbedUrl);
        if (!is_string($response) || $response === '') {
            return [];
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{description: string, duration: int}
     */
    private function fetchSoundCloudPageDetails(string $trackUrl): array
    {
        if (!$this->isSoundCloudTrackUrl($trackUrl)) {
            return ['description' => '', 'duration' => 0];
        }

        $response = GeneralUtility::getUrl($trackUrl);
        if (!is_string($response) || $response === '') {
            return ['description' => '', 'duration' => 0];
        }

        return [
            'description' => $this->parseSoundCloudDescriptionFromPageHtml($response),
            'duration' => $this->parseSoundCloudDurationFromPageHtml($response),
        ];
    }

    private function isSoundCloudTrackUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (!in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string)$parts['host']);

        return $host === 'soundcloud.com'
            || str_ends_with($host, '.soundcloud.com')
            || $host === 'on.soundcloud.com';
    }

    private function parseSoundCloudDurationFromPageHtml(string $html): int
    {
        if (preg_match('/"duration"\s*:\s*(\d+)/', $html, $matches) === 1) {
            return max(0, (int)round((int)$matches[1] / 1000));
        }

        if (preg_match('/itemprop="duration"\s+content="([^"]+)"/', $html, $matches) === 1) {
            return $this->parseIso8601Duration($matches[1]);
        }

        return 0;
    }

    private function parseSoundCloudDescriptionFromPageHtml(string $html): string
    {
        if (preg_match('/property="og:description"\s+content="([^"]*)"/', $html, $matches) !== 1) {
            return '';
        }

        return $this->normalizeText(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function parseIso8601Duration(string $value): int
    {
        if (preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $value, $matches) !== 1) {
            return 0;
        }

        return ((int)($matches[1] ?? 0)) * 3600
            + ((int)($matches[2] ?? 0)) * 60
            + ((int)($matches[3] ?? 0));
    }

    /**
     * @return array{description: string, duration: int}
     */
    private function fetchYouTubePageDetails(string $videoId): array
    {
        $response = GeneralUtility::getUrl('https://www.youtube.com/watch?v=' . rawurlencode($videoId));
        if (!is_string($response) || $response === '') {
            return ['description' => '', 'duration' => 0];
        }

        $duration = 0;
        if (preg_match('/"lengthSeconds"\s*:\s*"(\d+)"/', $response, $matches)) {
            $duration = max(0, (int)$matches[1]);
        }

        $description = '';
        if (preg_match('/"shortDescription"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $response, $matches)) {
            $description = $this->normalizeText(stripcslashes($matches[1]));
        }

        return [
            'description' => $description,
            'duration' => $duration,
        ];
    }

    private function resolveTitleFromFile(File $file): string
    {
        $title = $this->normalizeText((string)$file->getProperty('title'));
        if ($title !== '') {
            return $title;
        }

        return $this->normalizeText(pathinfo($file->getName(), PATHINFO_FILENAME));
    }

    private function normalizeText(string $value): string
    {
        return trim(strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function normalizeDuration(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_numeric($value)) {
            return max(0, (int)round((float)$value));
        }

        return 0;
    }
}
