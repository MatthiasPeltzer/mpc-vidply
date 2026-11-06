<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Form\DisplayCondition;

use Mpc\MpcVidply\Service\Streaming\StreamingSettings;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * FormEngine `USER:` display conditions of the streaming fields.
 */
final class StreamingDisplayCondition
{
    /**
     * The "convert" options are shown on default-language originals only,
     * never on a streaming variant.
     *
     * @param array<string, mixed> $parameters
     */
    public function canConvert(array $parameters): bool
    {
        $record = is_array($parameters['record'] ?? null) ? $parameters['record'] : [];

        return $this->isEnabled()
            && self::intValue($record['stream_source'] ?? 0) === 0
            && self::intValue($record['sys_language_uid'] ?? 0) <= 0;
    }

    /**
     * Status box: on originals with the option turned on, and on variants.
     *
     * @param array<string, mixed> $parameters
     */
    public function showStatus(array $parameters): bool
    {
        $record = is_array($parameters['record'] ?? null) ? $parameters['record'] : [];
        if (self::intValue($record['stream_source'] ?? 0) > 0) {
            return true;
        }

        return $this->canConvert($parameters) && self::intValue($record['stream_convert'] ?? 0) === 1;
    }

    private function isEnabled(): bool
    {
        return GeneralUtility::makeInstance(StreamingSettings::class)->isEnabled();
    }

    /**
     * FormEngine hands over select/language values as arrays (`[0 => '1']`).
     */
    private static function intValue(mixed $value): int
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_numeric($value) ? (int)$value : 0;
    }
}
