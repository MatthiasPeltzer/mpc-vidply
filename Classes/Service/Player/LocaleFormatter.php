<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Player;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Pre-formats the dates and file sizes the player and the episode list print.
 *
 * Both are rendered verbatim by JavaScript, which has no locale knowledge of the
 * site language, so the strings are built in PHP. The locale travels as an
 * argument rather than as state, so one instance can serve several requests.
 */
final class LocaleFormatter
{
    private const FILE_SIZE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    /**
     * Locale of the current site language (e.g. "de-DE"), or null when the
     * request carries no language (structured-data path).
     */
    public function resolveLocale(ServerRequestInterface $request): ?string
    {
        $language = $request->getAttribute('language');
        if ($language !== null && method_exists($language, 'getLocale')) {
            try {
                $locale = (string)$language->getLocale();
                if ($locale !== '') {
                    return $locale;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /**
     * Format a date-only timestamp for the current site language.
     *
     * TYPO3 stores date-only `datetime` fields as midnight **UTC**, so the
     * formatter is pinned to UTC as well — otherwise 2021-05-18 renders as
     * 17 May in any timezone behind UTC.
     */
    public function formatDate(int $timestamp, ?string $locale): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        if ($locale !== null && class_exists(\IntlDateFormatter::class)) {
            try {
                $formatter = new \IntlDateFormatter(
                    $locale,
                    \IntlDateFormatter::LONG,
                    \IntlDateFormatter::NONE,
                    'UTC'
                );
                $formatted = $formatter->format($timestamp);
                if (is_string($formatted) && $formatted !== '') {
                    return $formatted;
                }
            } catch (\Throwable) {
                // Fall through to the locale-independent format below.
            }
        }

        return gmdate('Y-m-d', $timestamp);
    }

    public function formatIsoDate(int $timestamp): string
    {
        return $timestamp > 0 ? gmdate('Y-m-d', $timestamp) : '';
    }

    /**
     * Byte count as "7.4 MB", localised like the player does: no decimals below
     * megabytes, one from there on.
     */
    public function formatFileSize(int $bytes, ?string $locale): string
    {
        if ($bytes <= 0) {
            return '';
        }

        $lastUnit = count(self::FILE_SIZE_UNITS) - 1;
        $value = (float)$bytes;
        $unitIndex = 0;
        while ($value >= 1024 && $unitIndex < $lastUnit) {
            $value /= 1024;
            $unitIndex++;
        }

        $decimals = $unitIndex < 2 ? 0 : 1;

        if ($locale !== null && class_exists(\NumberFormatter::class)) {
            try {
                $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
                $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
                $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
                $formatted = $formatter->format($value);
                if (is_string($formatted) && $formatted !== '') {
                    return $formatted . ' ' . self::FILE_SIZE_UNITS[$unitIndex];
                }
            } catch (\Throwable) {
                // Fall through to the locale-independent format below.
            }
        }

        return number_format($value, $decimals) . ' ' . self::FILE_SIZE_UNITS[$unitIndex];
    }
}
