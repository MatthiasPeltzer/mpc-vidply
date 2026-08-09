<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Player;

/**
 * Guards every URL the player assembly hands to a template.
 *
 * Posters, play icons and background images end up inside a CSS `url('…')`
 * literal or an HTML attribute, so a value that can close either context is
 * dropped rather than escaped.
 */
final class UrlSanitizer
{
    /**
     * Return the URL unchanged if it is safe to embed inside a CSS `url('...')`
     * literal, otherwise return null.
     *
     * Rejects:
     * - empty strings
     * - control characters (incl. CR/LF/TAB)
     * - characters that can break out of the `url()` / attribute context:
     *   '"', "'", '(', ')', '\\', '<', '>', '`'
     * - non-http(s) schemes (a relative/root-relative path is accepted)
     */
    public function sanitizeForCssUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (preg_match('/[\x00-\x1f\x7f"\'()\\\\<>`]/', $url)) {
            return null;
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $url)) {
            $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
            if ($scheme !== 'http' && $scheme !== 'https') {
                return null;
            }
        }

        return $url;
    }

    /**
     * Validate that a configured external play-icon URL matches the admin-configured
     * allow-list. If no allow-list is configured, external URLs are rejected — admins
     * can still reference FAL/EXT: icons safely.
     *
     * @param array<string, mixed> $extConf
     */
    public function validateExternalIconUrl(string $configuredIcon, array $extConf): ?string
    {
        $parsed = parse_url($configuredIcon);
        $scheme = strtolower((string)($parsed['scheme'] ?? ''));
        $host = strtolower((string)($parsed['host'] ?? ''));

        if ($scheme === '' && $host === '') {
            return $configuredIcon;
        }

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $raw = (string)($extConf['allowedPlayIconDomains'] ?? '');
        $items = preg_split('/[,\r\n]+/', $raw) ?: [];
        $allowedPatterns = array_values(array_filter(array_map('trim', $items), static fn (string $v): bool => $v !== ''));
        if ($allowedPatterns === []) {
            return null;
        }

        foreach ($allowedPatterns as $pattern) {
            $pattern = strtolower($pattern);
            if ($pattern === $host) {
                return $configuredIcon;
            }
            if (str_starts_with($pattern, '*.')) {
                $base = substr($pattern, 2);
                if ($base === '' || !str_contains($base, '.')) {
                    continue;
                }
                if ($host === $base || str_ends_with($host, '.' . $base)) {
                    return $configuredIcon;
                }
            }
        }

        return null;
    }

    /**
     * Strip control characters from a free-text value that is printed as-is,
     * e.g. a `<track label>` coming from the file metadata.
     */
    public function stripControlChars(string $value): string
    {
        return (string)preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $value);
    }
}
