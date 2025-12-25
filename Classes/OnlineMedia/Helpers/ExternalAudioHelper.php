<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\OnlineMedia\Helpers;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\Exception\OnlineMediaAlreadyExistsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\AbstractOnlineMediaHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ExternalAudioHelper extends AbstractOnlineMediaHelper
{
    private ExtensionConfiguration $extensionConfiguration;

    public function __construct($extension, ?ExtensionConfiguration $extensionConfiguration = null)
    {
        parent::__construct($extension);
        $this->extensionConfiguration = $extensionConfiguration ?? GeneralUtility::makeInstance(ExtensionConfiguration::class);
    }

    public function transformUrlToFile($url, Folder $targetFolder)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $path = (string)($parts['path'] ?? '');
        $fileExtension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($fileExtension, ['mp3', 'wav', 'm4a', 'aac', 'flac', 'oga'], true)) {
            return null;
        }

        if (!$this->isHostAllowed($scheme, strtolower((string)$parts['host']), $this->getAllowedDomains('allowedAudioDomains'))) {
            return null;
        }

        $onlineMediaId = $url;
        $existing = $this->findExistingFileByOnlineMediaId($onlineMediaId, $targetFolder, $this->extension);
        if ($existing !== null) {
            throw new OnlineMediaAlreadyExistsException($existing, 1735061002);
        }

        $baseName = basename($path);
        $fileName = $this->buildFileName($baseName !== '' ? $baseName : 'audio.' . $fileExtension, $this->extension);
        return $this->createNewFile($targetFolder, $fileName, $onlineMediaId);
    }

    public function getPublicUrl(File $file)
    {
        $url = $this->getOnlineMediaId($file);
        return $url !== '' ? $url : null;
    }

    public function getPreviewImage(File $file)
    {
        // Used by TYPO3 Filelist thumbnail rendering for online media files
        return (string)GeneralUtility::getFileAbsFileName('EXT:mpc_vidply/Resources/Public/Images/audio.png');
    }

    public function getMetaData(File $file)
    {
        $url = $this->getOnlineMediaId($file);
        if ($url === '') {
            return [];
        }

        $parts = parse_url($url);
        $path = is_array($parts) ? (string)($parts['path'] ?? '') : '';
        $name = basename($path);

        return $name !== '' ? ['title' => $name] : [];
    }

    /**
     * @return string[]
     */
    private function getAllowedDomains(string $key): array
    {
        try {
            $config = $this->extensionConfiguration->get('mpc_vidply');
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
                // parse_url() is not reliable with wildcard hosts, so parse manually
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

    private function buildFileName(string $baseName, string $containerExtension): string
    {
        $baseName = trim($baseName);
        if ($baseName === '') {
            $baseName = 'external-audio';
        }
        $baseName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $baseName) ?? 'external-audio';
        $nameWithoutExtension = pathinfo($baseName, PATHINFO_FILENAME);
        if ($nameWithoutExtension === '') {
            $nameWithoutExtension = 'external-audio';
        }
        return $nameWithoutExtension . '.' . $containerExtension;
    }

    // no preview image generation needed (we ship a static preview image)
}


