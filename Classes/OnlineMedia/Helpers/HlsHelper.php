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
 * Online media helper for HLS playlist URLs.
 *
 * Creates a FAL container file with extension ".hls" that stores the playlist URL as online media id.
 */
final class HlsHelper extends AbstractOnlineMediaHelper
{
    use ExternalMediaDomainValidationTrait;

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
        // HLS playlists are typically .m3u8
        if ($fileExtension !== 'm3u8') {
            return null;
        }

        if (!$this->isHostAllowed($scheme, strtolower((string)$parts['host']), $this->getAllowedDomains('allowedVideoDomains'))) {
            return null;
        }

        $onlineMediaId = $url;
        $existing = $this->findExistingFileByOnlineMediaId($onlineMediaId, $targetFolder, $this->extension);
        if ($existing !== null) {
            throw new OnlineMediaAlreadyExistsException($existing, 1735063001);
        }

        $baseName = basename($path);
        $fileName = $this->buildFileName($baseName !== '' ? $baseName : 'stream.m3u8', $this->extension, 'hls');
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
        return (string)GeneralUtility::getFileAbsFileName('EXT:mpc_vidply/Resources/Public/Images/video.png');
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

}


