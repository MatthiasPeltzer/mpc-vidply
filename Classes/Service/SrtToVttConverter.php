<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use Mpc\MpcVidply\Dto\SrtConversionResult;

/**
 * Converts SubRip (.srt) caption text to WebVTT (.vtt).
 */
final class SrtToVttConverter
{
    private const TIMESTAMP_PATTERN = '/^(\d{1,2}:)?(\d{2}):(\d{2})[,.](\d{3})\s*-->\s*(\d{1,2}:)?(\d{2}):(\d{2})[,.](\d{3})(?:\s|$)/';

    public function convert(string $srtContent): SrtConversionResult
    {
        $normalized = $this->normalizeEncoding($srtContent);
        if ($normalized === '') {
            return SrtConversionResult::fail('The subtitle file is empty.');
        }

        $blocks = preg_split('/\R\R+/u', trim($normalized)) ?: [];
        $cues = [];

        foreach ($blocks as $block) {
            $cue = $this->parseBlock(trim($block));
            if ($cue !== null) {
                $cues[] = $cue;
            }
        }

        if ($cues === []) {
            return SrtConversionResult::fail('No valid subtitle cues were found in the SRT file.');
        }

        $lines = ['WEBVTT', ''];
        foreach ($cues as $cue) {
            $lines[] = $cue['start'] . ' --> ' . $cue['end'];
            $lines[] = $cue['text'];
            $lines[] = '';
        }

        return SrtConversionResult::ok(rtrim(implode("\n", $lines)) . "\n");
    }

    private function normalizeEncoding(string $content): string
    {
        if ($content === '') {
            return '';
        }

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        $converted = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        if (!is_string($converted) || $converted === '') {
            return $content;
        }

        return $converted;
    }

    /**
     * @return array{start: string, end: string, text: string}|null
     */
    private function parseBlock(string $block): ?array
    {
        if ($block === '') {
            return null;
        }

        $lines = preg_split('/\R/u', $block) ?: [];
        $timestampLineIndex = null;

        foreach ($lines as $index => $line) {
            if (preg_match(self::TIMESTAMP_PATTERN, trim($line)) === 1) {
                $timestampLineIndex = $index;
                break;
            }
        }

        if ($timestampLineIndex === null) {
            return null;
        }

        $timestampLine = trim($lines[$timestampLineIndex]);
        if (preg_match(self::TIMESTAMP_PATTERN, $timestampLine, $matches) !== 1) {
            return null;
        }

        $start = $this->formatVttTimestamp($matches[1], $matches[2], $matches[3], $matches[4]);
        $end = $this->formatVttTimestamp($matches[5], $matches[6], $matches[7], $matches[8]);

        $textLines = array_slice($lines, $timestampLineIndex + 1);
        $text = $this->sanitizeCueText(implode("\n", $textLines));

        if ($text === '') {
            return null;
        }

        return [
            'start' => $start,
            'end' => $end,
            'text' => $text,
        ];
    }

    private function formatVttTimestamp(?string $hours, string $minutes, string $seconds, string $milliseconds): string
    {
        $hourPart = $hours !== null && $hours !== ''
            ? str_pad(rtrim($hours, ':'), 2, '0', STR_PAD_LEFT)
            : '00';

        return sprintf(
            '%s:%s:%s.%s',
            $hourPart,
            str_pad($minutes, 2, '0', STR_PAD_LEFT),
            str_pad($seconds, 2, '0', STR_PAD_LEFT),
            str_pad($milliseconds, 3, '0', STR_PAD_LEFT)
        );
    }

    private function sanitizeCueText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // Remove ASS/SSA positioning and styling directives unsupported in WebVTT.
        $text = (string)preg_replace('/\{\\\\[^}]+\}/u', '', $text);
        // Strip legacy font tags but keep their inner text.
        $text = (string)preg_replace('/<\/?font\b[^>]*>/iu', '', $text);

        return trim($text);
    }
}
