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
 * Online media helper for DASH manifest URLs (.mpd).
 *
 * Creates a FAL container file with extension ".dash" that stores the manifest URL as online media id.
 */
final class DashHelper extends AbstractOnlineMediaHelper
{
    use ExternalMediaDomainValidationTrait;

    private readonly ExtensionConfiguration $extensionConfiguration;

    public function __construct($extension, ?ExtensionConfiguration $extensionConfiguration = null)
    {
        parent::__construct($extension);
        $this->extensionConfiguration = $extensionConfiguration ?? GeneralUtility::makeInstance(ExtensionConfiguration::class);
    }

    private function getExtensionConfiguration(): ExtensionConfiguration
    {
        return $this->extensionConfiguration;
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
        if ($fileExtension !== 'mpd') {
            return null;
        }

        if (!$this->isHostAllowed($scheme, strtolower((string)$parts['host']), $this->getAllowedDomains('allowedVideoDomains'))) {
            return null;
        }

        $onlineMediaId = $url;
        $existing = $this->findExistingFileByOnlineMediaId($onlineMediaId, $targetFolder, $this->extension);
        if ($existing !== null) {
            throw new OnlineMediaAlreadyExistsException($existing, 1735063002);
        }

        $baseName = basename($path);
        $fileName = $this->buildFileName($baseName !== '' ? $baseName : 'stream.mpd', $this->extension, 'dash');
        return $this->createNewFile($targetFolder, $fileName, $onlineMediaId);
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
}
