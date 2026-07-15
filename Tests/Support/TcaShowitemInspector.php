<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Support;

/**
 * Validates showitem strings against merged TCA columns and palettes.
 */
final class TcaShowitemInspector
{
    /**
     * @return list<string>
     */
    public function inspectTableType(string $table, string $typeName): array
    {
        $tca = $GLOBALS['TCA'][$table] ?? null;
        if (!is_array($tca)) {
            return [sprintf('Table "%s" is missing from $GLOBALS[\'TCA\'].', $table)];
        }

        $showitem = $tca['types'][$typeName]['showitem'] ?? null;
        if (!is_string($showitem) || trim($showitem) === '') {
            return [sprintf('Table "%s" type "%s" has no showitem definition.', $table, $typeName)];
        }

        $columns = $this->resolveColumns($table, $typeName, $tca);
        $palettes = is_array($tca['palettes'] ?? null) ? $tca['palettes'] : [];

        return $this->inspectShowitem($showitem, $columns, $palettes, $table, $typeName);
    }

    /**
     * @param array<string, mixed> $tca
     *
     * @return array<string, mixed>
     */
    private function resolveColumns(string $table, string $typeName, array $tca): array
    {
        $columns = is_array($tca['columns'] ?? null) ? $tca['columns'] : [];
        $overrides = $tca['types'][$typeName]['columnsOverrides'] ?? null;
        if (!is_array($overrides)) {
            return $columns;
        }

        foreach ($overrides as $fieldName => $override) {
            if (!is_string($fieldName) || !is_array($override)) {
                continue;
            }
            $base = is_array($columns[$fieldName] ?? null) ? $columns[$fieldName] : [];
            $columns[$fieldName] = array_replace_recursive($base, $override);
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $columns
     * @param array<string, mixed> $palettes
     *
     * @return list<string>
     */
    private function inspectShowitem(
        string $showitem,
        array $columns,
        array $palettes,
        string $table,
        string $typeName,
    ): array {
        $violations = [];

        foreach ($this->parseShowitemTokens($showitem) as $token) {
            if (str_starts_with($token, '--div--') || $token === '--linebreak--') {
                continue;
            }
            if (str_starts_with($token, '--palette--')) {
                $paletteName = $this->extractPaletteName($token);
                if ($paletteName === null) {
                    $violations[] = sprintf(
                        'Table "%s" type "%s" has malformed palette token "%s".',
                        $table,
                        $typeName,
                        $token,
                    );
                    continue;
                }
                if (!isset($palettes[$paletteName])) {
                    $violations[] = sprintf(
                        'Table "%s" type "%s" references unknown palette "%s".',
                        $table,
                        $typeName,
                        $paletteName,
                    );
                    continue;
                }
                $paletteShowitem = $palettes[$paletteName]['showitem'] ?? '';
                if (!is_string($paletteShowitem)) {
                    $violations[] = sprintf(
                        'Table "%s" palette "%s" has no showitem string.',
                        $table,
                        $paletteName,
                    );
                    continue;
                }
                $violations = [
                    ...$violations,
                    ...$this->inspectShowitem($paletteShowitem, $columns, $palettes, $table, $typeName),
                ];
                continue;
            }

            $fieldName = $this->extractFieldName($token);
            if ($fieldName === null || $fieldName === '') {
                continue;
            }
            if (!isset($columns[$fieldName])) {
                $violations[] = sprintf(
                    'Table "%s" type "%s" references unknown field "%s".',
                    $table,
                    $typeName,
                    $fieldName,
                );
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function parseShowitemTokens(string $showitem): array
    {
        $normalized = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $showitem);
        $parts = array_map('trim', explode(',', $normalized));

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function extractPaletteName(string $token): ?string
    {
        if (!str_starts_with($token, '--palette--')) {
            return null;
        }

        $segments = explode(';', $token);
        $name = trim((string)end($segments));

        return $name !== '' ? $name : null;
    }

    private function extractFieldName(string $token): ?string
    {
        if (str_starts_with($token, '--')) {
            return null;
        }

        $segments = explode(';', $token, 2);

        return trim($segments[0]);
    }
}
