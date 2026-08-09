<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\OnlineMedia\Helpers;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\Exception\OnlineMediaAlreadyExistsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\AbstractOnlineMediaHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Base for the online media helpers that store a remote media URL in a FAL
 * container file instead of downloading the media itself.
 *
 * Subclasses only declare which URLs they accept and how the container file is
 * named; the URL parsing, host allow-listing and file creation are identical
 * for direct video/audio files and for HLS/DASH manifests.
 */
abstract class AbstractExternalMediaHelper extends AbstractOnlineMediaHelper
{
    use ExternalMediaDomainValidationTrait;

    private readonly ExtensionConfiguration $extensionConfiguration;

    public function __construct($extension, ?ExtensionConfiguration $extensionConfiguration = null)
    {
        parent::__construct($extension);
        $this->extensionConfiguration = $extensionConfiguration
            ?? GeneralUtility::makeInstance(ExtensionConfiguration::class);
    }

    /** @return File|null */
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
        if (!in_array($fileExtension, $this->getSupportedFileExtensions(), true)) {
            return null;
        }

        $allowedDomains = $this->getAllowedDomains($this->getAllowedDomainsConfigKey());
        if (!$this->isHostAllowed($scheme, strtolower((string)$parts['host']), $allowedDomains)) {
            return null;
        }

        // The full URL doubles as the "online media id".
        $onlineMediaId = $url;
        $existing = $this->findExistingFileByOnlineMediaId($onlineMediaId, $targetFolder, $this->extension);
        if ($existing !== null) {
            throw new OnlineMediaAlreadyExistsException($existing, $this->getAlreadyExistsExceptionCode());
        }

        $baseName = basename($path);
        if ($baseName === '') {
            $baseName = $this->getDefaultBaseNamePrefix() . '.' . $fileExtension;
        }

        return $this->createNewFile(
            $targetFolder,
            $this->buildFileName($baseName, $this->extension, $this->getFileNameFallback()),
            $onlineMediaId
        );
    }

    /** @return string|null */
    public function getPublicUrl(File $file)
    {
        $url = $this->getOnlineMediaId($file);

        return $url !== '' ? $url : null;
    }

    /** @return string */
    public function getPreviewImage(File $file)
    {
        return (string)GeneralUtility::getFileAbsFileName('EXT:mpc_vidply/Resources/Public/Icons/Extension.svg');
    }

    /** @return array<string, mixed> */
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
     * File extensions this helper accepts in the source URL.
     *
     * @return list<string>
     */
    abstract protected function getSupportedFileExtensions(): array;

    /**
     * Extension-configuration key holding the host allow-list for this media kind.
     */
    abstract protected function getAllowedDomainsConfigKey(): string;

    /**
     * Distinct per helper so the log points at the right importer.
     */
    abstract protected function getAlreadyExistsExceptionCode(): int;

    /**
     * Used when the URL has no file name of its own, as in `https://host/live/`.
     */
    abstract protected function getDefaultBaseNamePrefix(): string;

    /**
     * Used when the derived base name sanitizes down to nothing.
     */
    abstract protected function getFileNameFallback(): string;

    private function getExtensionConfiguration(): ExtensionConfiguration
    {
        return $this->extensionConfiguration;
    }
}
