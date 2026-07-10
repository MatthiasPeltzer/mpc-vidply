<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Utility;

/**
 * Normalises values that may be plain scalars, arrays, or TYPO3 v14 Record objects.
 *
 * TYPO3 v13 typically exposes database rows as arrays; v14 may wrap fields and
 * records in objects exposing {@see toArray()} and/or {@see getUid()}.
 */
final class RecordAwareValueResolver
{
    /**
     * @return array<string, mixed>
     */
    public static function normalizeToArray(mixed $value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }

        return is_array($value) ? $value : [];
    }

    public static function resolveScalar(mixed $value, string $default = ''): string
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        if (is_object($value)) {
            if (method_exists($value, 'getUid')) {
                return (string)$value->getUid();
            }

            if (method_exists($value, 'toArray')) {
                $array = $value->toArray();
                if (isset($array['uid'])) {
                    return (string)$array['uid'];
                }
                if (isset($array['value'])) {
                    return (string)$array['value'];
                }
            }

            if ($value instanceof \Traversable) {
                foreach ($value as $entry) {
                    return self::resolveScalar($entry, $default);
                }
            }

            return $default;
        }

        if (is_array($value)) {
            if ($value === []) {
                return $default;
            }

            $first = reset($value);
            if (is_array($first)) {
                if (isset($first['value'])) {
                    return (string)$first['value'];
                }
                if (isset($first['uid'])) {
                    return (string)$first['uid'];
                }
            }

            return self::resolveScalar($first, $default);
        }

        return $default;
    }

    public static function resolveInt(mixed $value, int $default = 0): int
    {
        $scalar = self::resolveScalar($value, '');
        if ($scalar === '') {
            return $default;
        }

        return (int)$scalar;
    }
}
