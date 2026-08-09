<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

/**
 * Formats a media duration as the clock-style label used across the player,
 * the listview cards, the detail page and the backend previews.
 */
final class DurationFormatter
{
    /**
     * @return string `m:ss`, or `h:mm:ss` once the duration reaches an hour;
     *                empty for a missing or invalid duration.
     */
    public function format(int $seconds): string
    {
        if ($seconds <= 0) {
            return '';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }
}
