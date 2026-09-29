<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Utility;

/**
 * Parses absolute http(s) URLs and rejects the shapes on which PHP's
 * `parse_url()` and browsers disagree about the host.
 *
 * `https://evil.tld\@cdn.allowed.com/a.m3u8` is reported by PHP as host
 * `cdn.allowed.com`, while browsers treat the backslash as a path separator
 * and load from `evil.tld`. Backslashes, whitespace, control characters and
 * any user/password part are therefore refused outright instead of being
 * interpreted.
 */
final class HttpUrlGuard
{
    /**
     * @return array{scheme: string, host: string, port?: int, path?: string, query?: string, fragment?: string}|null
     */
    public static function parse(string $url): ?array
    {
        $url = trim($url, " \t\n\r");
        if ($url === '' || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $result = ['scheme' => $scheme, 'host' => strtolower($parts['host'])];
        if (isset($parts['port'])) {
            $result['port'] = $parts['port'];
        }
        foreach (['path', 'query', 'fragment'] as $key) {
            if (isset($parts[$key]) && $parts[$key] !== '') {
                $result[$key] = $parts[$key];
            }
        }

        return $result;
    }

    /**
     * Rebuild a URL from the parts returned by {@see parse()}, so the stored
     * value is exactly what was validated.
     *
     * @param array{scheme: string, host: string, port?: int, path?: string, query?: string, fragment?: string} $parts
     */
    public static function build(array $parts): string
    {
        $url = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }
        $url .= $parts['path'] ?? '';
        if (isset($parts['query'])) {
            $url .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
    }

    /**
     * Whether a URL may be printed into an `href`/`src`: an absolute http(s)
     * URL that passes {@see parse()}, or a relative path such as FAL returns
     * for local files. Protocol-relative `//host` URLs and any other scheme
     * (`javascript:`, `data:`, …) are refused.
     */
    public static function isSafeLinkTarget(string $url): bool
    {
        $url = trim($url, " \t\n\r");
        if ($url === '') {
            return false;
        }
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $url) === 1) {
            return self::parse($url) !== null;
        }

        return !str_starts_with($url, '//')
            && preg_match('/[\s\x00-\x1f\x7f\\\\]/', $url) !== 1;
    }
}
