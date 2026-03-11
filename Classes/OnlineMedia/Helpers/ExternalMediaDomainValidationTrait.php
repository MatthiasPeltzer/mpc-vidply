<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\OnlineMedia\Helpers;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Shared domain validation and file naming logic for external media OnlineMedia helpers.
 */
trait ExternalMediaDomainValidationTrait
{
    abstract private function getExtensionConfiguration(): ExtensionConfiguration;

    /**
     * @return string[]
     */
    private function getAllowedDomains(string $key): array
    {
        try {
            $config = $this->getExtensionConfiguration()->get('mpc_vidply');
        } catch (\Throwable) {
            $config = [];
        }

        $raw = (string)($config[$key] ?? '');
        $items = preg_split('/[,\r\n]+/', $raw) ?: [];
        $items = array_map(static fn(string $v): string => trim($v), $items);
        return array_values(array_filter($items, static fn(string $v): bool => $v !== ''));
    }

    /**
     * @param string[] $allowedDomainPatterns
     */
    private function isHostAllowed(string $scheme, string $host, array $allowedDomainPatterns): bool
    {
        if ($allowedDomainPatterns === []) {
            return false;
        }

        foreach ($allowedDomainPatterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }

            $patternScheme = null;
            $patternHost = $pattern;
            if (str_contains($pattern, '://')) {
                [$patternScheme, $patternHost] = explode('://', $pattern, 2);
                $patternScheme = strtolower(trim($patternScheme));
                $patternHost = trim($patternHost);
                $patternHost = explode('/', $patternHost, 2)[0];
                $patternHost = strtolower($patternHost);
            } else {
                $patternHost = strtolower($patternHost);
            }

            if ($patternScheme !== null && $patternScheme !== '' && $patternScheme !== $scheme) {
                continue;
            }

            if ($patternHost === $host) {
                return true;
            }

            if (str_starts_with($patternHost, '*.')) {
                $base = substr($patternHost, 2);
                if ($base !== '' && ($host === $base || str_ends_with($host, '.' . $base))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function buildFileName(string $baseName, string $containerExtension, string $fallbackName = 'media'): string
    {
        $baseName = trim($baseName);
        if ($baseName === '') {
            $baseName = $fallbackName;
        }
        $baseName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $baseName) ?? $fallbackName;
        $nameWithoutExtension = pathinfo($baseName, PATHINFO_FILENAME);
        if ($nameWithoutExtension === '') {
            $nameWithoutExtension = $fallbackName;
        }
        return $nameWithoutExtension . '.' . $containerExtension;
    }
}
