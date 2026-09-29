<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\OnlineMedia\Helpers;

use Mpc\MpcVidply\Utility\HttpUrlGuard;
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
        $parts = $this->parseAllowedUrl((string)$url);
        if ($parts === null) {
            return null;
        }

        $path = $parts['path'] ?? '';
        $fileExtension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($fileExtension, $this->getSupportedFileExtensions(), true)) {
            return null;
        }

        // The full URL doubles as the "online media id". It is rebuilt from the
        // validated parts so the stored value is exactly what was checked.
        $onlineMediaId = HttpUrlGuard::build($parts);
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

    /**
     * The container file content is editor-controlled: the container extensions
     * are also regular upload extensions, so a file can reach this point
     * without ever passing {@see transformUrlToFile()}. The URL is therefore
     * re-validated on every read.
     *
     * @return string|null
     */
    public function getPublicUrl(File $file)
    {
        $parts = $this->parseAllowedUrl($this->getOnlineMediaId($file));

        return $parts !== null ? HttpUrlGuard::build($parts) : null;
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

    /**
     * @return array{scheme: string, host: string, port?: int, path?: string, query?: string, fragment?: string}|null
     */
    private function parseAllowedUrl(string $url): ?array
    {
        $parts = HttpUrlGuard::parse($url);
        if ($parts === null) {
            return null;
        }

        $allowedDomains = $this->getAllowedDomains($this->getAllowedDomainsConfigKey());

        return $this->isHostAllowed($parts['scheme'], $parts['host'], $allowedDomains) ? $parts : null;
    }

    private function getExtensionConfiguration(): ExtensionConfiguration
    {
        return $this->extensionConfiguration;
    }
}
