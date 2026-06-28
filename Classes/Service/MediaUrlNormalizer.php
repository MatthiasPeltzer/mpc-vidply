<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

/**
 * Normalizes pasted media URLs and extracts platform identifiers.
 */
final class MediaUrlNormalizer
{
    /**
     * Normalize a pasted URL for online-media import.
     */
    public function normalize(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $url)) {
            if ($this->looksLikeUrlWithoutScheme($url)) {
                $url = 'https://' . ltrim($url, '/');
            } else {
                return null;
            }
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string)$parts['host']);
        if (!$this->isEmbedHost($host)) {
            return $this->stripTrackingParams($url, $parts);
        }

        return $url;
    }

    /**
     * Extract a YouTube video ID (11 characters) from a URL or bare ID.
     *
     * Uses the same broad pattern as TYPO3 core {@see \TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\YouTubeHelper}.
     */
    public function extractYouTubeId(string $src): ?string
    {
        if ($src === '') {
            return null;
        }

        if (preg_match(
            '%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?|shorts|live)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i',
            $src,
            $matches
        ) === 1) {
            return $matches[1];
        }

        if (preg_match('~^[A-Za-z0-9_-]{11}$~', $src) === 1) {
            return $src;
        }

        return null;
    }

    /**
     * Extract a Vimeo numeric video ID from a URL or bare ID.
     */
    public function extractVimeoId(string $src): ?string
    {
        if ($src === '') {
            return null;
        }

        if (preg_match('~(?:vimeo\.com/(?:video/)?|player\.vimeo\.com/video/)(\d+)~', $src, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('~^\d+$~', $src) === 1) {
            return $src;
        }

        return null;
    }

    /**
     * Build a canonical YouTube watch URL from any supported YouTube URL variant.
     */
    public function toCanonicalYouTubeWatchUrl(string $url): ?string
    {
        $videoId = $this->extractYouTubeId($url);
        if ($videoId === null) {
            return null;
        }

        return 'https://www.youtube.com/watch?v=' . rawurlencode($videoId);
    }

    private function looksLikeUrlWithoutScheme(string $url): bool
    {
        if (str_contains($url, 'youtu') || str_contains($url, 'vimeo.com') || str_contains($url, 'soundcloud.com')) {
            return true;
        }

        return (bool)preg_match('#^[a-z0-9.-]+\.[a-z]{2,}/#i', $url);
    }

    private function isEmbedHost(string $host): bool
    {
        return str_contains($host, 'youtube')
            || str_contains($host, 'youtu.be')
            || str_contains($host, 'vimeo.com')
            || $host === 'soundcloud.com'
            || str_ends_with($host, '.soundcloud.com')
            || $host === 'on.soundcloud.com';
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function stripTrackingParams(string $url, array $parts): string
    {
        if (empty($parts['query'])) {
            return $url;
        }

        parse_str((string)$parts['query'], $queryParams);
        if (!is_array($queryParams) || $queryParams === []) {
            return $url;
        }

        foreach (array_keys($queryParams) as $key) {
            $lowerKey = strtolower((string)$key);
            if (str_starts_with($lowerKey, 'utm_') || $lowerKey === 'fbclid' || $lowerKey === 'gclid') {
                unset($queryParams[$key]);
            }
        }

        $scheme = (string)($parts['scheme'] ?? 'https');
        $host = (string)($parts['host'] ?? '');
        $path = (string)($parts['path'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $query = $queryParams !== [] ? '?' . http_build_query($queryParams) : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . '://' . $host . $port . $path . $query . $fragment;
    }
}
