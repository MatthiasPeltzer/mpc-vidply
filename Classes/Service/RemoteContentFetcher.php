<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use Mpc\MpcVidply\Utility\HttpUrlGuard;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Bounded outbound HTTP for the backend media import (oEmbed, provider pages,
 * poster thumbnails).
 *
 * Unlike {@see \TYPO3\CMS\Core\Utility\GeneralUtility::getUrl()} it never
 * falls back to `file_get_contents()` for non-HTTP values, applies connect and
 * total timeouts, and aborts once a body grows past the given size.
 */
final class RemoteContentFetcher
{
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 10;
    private const CHUNK_SIZE = 8192;

    /**
     * Hosts poster thumbnails may be downloaded from (exact or `*.` wildcard).
     */
    public const THUMBNAIL_HOSTS = ['i.ytimg.com', 'i.vimeocdn.com', 'vumbnail.com', '*.sndcdn.com'];

    /**
     * MIME type => file extension of the poster image formats accepted.
     */
    public const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    /**
     * GET an http(s) URL and return its body, or null on any error, non-200
     * status or a body larger than $maxBytes.
     */
    public function fetch(string $url, int $maxBytes, bool $followRedirects = true): ?string
    {
        if (HttpUrlGuard::parse($url) === null) {
            return null;
        }

        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout' => self::TIMEOUT,
                'allow_redirects' => $followRedirects
                    ? ['max' => 3, 'protocols' => ['https', 'http'], 'strict' => true]
                    : false,
                'http_errors' => false,
                'stream' => true,
            ]);
        } catch (\Throwable) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }
        $contentLength = $response->getHeaderLine('Content-Length');
        if ($contentLength !== '' && (int)$contentLength > $maxBytes) {
            return null;
        }

        $body = $response->getBody();
        $content = '';
        try {
            while (!$body->eof()) {
                $content .= $body->read(self::CHUNK_SIZE);
                if (strlen($content) > $maxBytes) {
                    return null;
                }
            }
        } catch (\Throwable) {
            return null;
        } finally {
            $body->close();
        }

        return $content;
    }

    /**
     * Download a poster thumbnail from one of {@see THUMBNAIL_HOSTS}.
     * Redirects are not followed, so the allow-list cannot be sidestepped.
     *
     * @return array{binary: string, extension: string}|null
     */
    public function fetchThumbnail(string $url): ?array
    {
        $parts = HttpUrlGuard::parse($url);
        if ($parts === null || $parts['scheme'] !== 'https' || !self::isThumbnailHost($parts['host'])) {
            return null;
        }

        $binary = $this->fetch($url, self::MAX_IMAGE_BYTES, false);
        if ($binary === null) {
            return null;
        }
        $extension = self::detectImageExtension($binary);

        return $extension !== null ? ['binary' => $binary, 'extension' => $extension] : null;
    }

    /**
     * File extension for a JPEG, PNG or WebP binary, detected from its content
     * rather than trusted from a URL or header; null for anything else.
     */
    public static function detectImageExtension(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }
        // getimagesizefromstring() parses the image header, so SVG, HTML or a
        // truncated body are rejected; it needs no optional PHP extension.
        $info = @getimagesizefromstring($binary);
        if (!is_array($info)) {
            return null;
        }

        return self::IMAGE_TYPES[$info['mime']] ?? null;
    }

    public static function isThumbnailHost(string $host): bool
    {
        $host = strtolower($host);
        foreach (self::THUMBNAIL_HOSTS as $pattern) {
            if (str_starts_with($pattern, '*.')) {
                if (str_ends_with($host, substr($pattern, 1))) {
                    return true;
                }
            } elseif ($host === $pattern) {
                return true;
            }
        }

        return false;
    }
}
