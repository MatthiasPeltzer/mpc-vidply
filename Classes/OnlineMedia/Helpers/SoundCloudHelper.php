<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\OnlineMedia\Helpers;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\Exception\OnlineMediaAlreadyExistsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\AbstractOnlineMediaHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Online media helper for SoundCloud.
 *
 * Stores the full SoundCloud URL as "online media id" in a FAL container file (.soundcloud).
 * Uses oEmbed to fetch metadata and a thumbnail for Filelist previews.
 */
final class SoundCloudHelper extends AbstractOnlineMediaHelper
{
    public function getPublicUrl(File $file)
    {
        $url = $this->getOnlineMediaId($file);
        return $url !== '' ? $url : null;
    }

    public function getPreviewImage(File $file)
    {
        $url = $this->getOnlineMediaId($file);
        if ($url === '') {
            // fallback icon
            return (string)GeneralUtility::getFileAbsFileName('EXT:mpc_vidply/Resources/Public/Images/audio.png');
        }

        $cacheFile = $this->getTempFolderPath() . 'soundcloud_' . md5($url) . '.jpg';
        if (!file_exists($cacheFile)) {
            $oEmbed = $this->getOEmbedData($url);
            $thumbUrl = is_array($oEmbed) ? (string)($oEmbed['thumbnail_url'] ?? '') : '';
            if ($thumbUrl !== '') {
                $image = GeneralUtility::getUrl($thumbUrl);
                if ($image !== false && $image !== '') {
                    GeneralUtility::writeFile($cacheFile, $image, true);
                }
            }
        }

        return file_exists($cacheFile)
            ? $cacheFile
            : (string)GeneralUtility::getFileAbsFileName('EXT:mpc_vidply/Resources/Public/Images/audio.png');
    }

    public function getMetaData(File $file)
    {
        $url = $this->getOnlineMediaId($file);
        if ($url === '') {
            return [];
        }

        $oEmbed = $this->getOEmbedData($url);
        if (!is_array($oEmbed) || $oEmbed === []) {
            return [];
        }

        $metadata = [];
        if (empty($file->getProperty('title')) && !empty($oEmbed['title'])) {
            $metadata['title'] = strip_tags((string)$oEmbed['title']);
        }
        if (!empty($oEmbed['author_name'])) {
            $metadata['author'] = (string)$oEmbed['author_name'];
        }
        return $metadata;
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

        $host = strtolower((string)$parts['host']);
        // Accept both canonical and short-link hosts
        if (!($host === 'soundcloud.com' || str_ends_with($host, '.soundcloud.com') || $host === 'on.soundcloud.com')) {
            return null;
        }

        $onlineMediaId = $url;
        $existing = $this->findExistingFileByOnlineMediaId($onlineMediaId, $targetFolder, $this->extension);
        if ($existing !== null) {
            throw new OnlineMediaAlreadyExistsException($existing, 1735062001);
        }

        // Try to use the oEmbed title for the filename, otherwise a generic one
        $fileNameBase = 'soundcloud';
        $oEmbed = $this->getOEmbedData($url);
        if (is_array($oEmbed) && !empty($oEmbed['title'])) {
            $fileNameBase = (string)$oEmbed['title'];
        }
        $fileNameBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $fileNameBase) ?? 'soundcloud';
        $fileNameBase = trim($fileNameBase, '._-');
        if ($fileNameBase === '') {
            $fileNameBase = 'soundcloud';
        }

        return $this->createNewFile($targetFolder, $fileNameBase . '.' . $this->extension, $onlineMediaId);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getOEmbedData(string $url): ?array
    {
        $oEmbedUrl = sprintf(
            'https://soundcloud.com/oembed?format=json&url=%s',
            rawurlencode($url)
        );
        $raw = (string)GeneralUtility::getUrl($oEmbedUrl);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}


