<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\OnlineMedia\Helpers;

use Mpc\MpcVidply\Service\RemoteContentFetcher;
use Mpc\MpcVidply\Utility\HttpUrlGuard;
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
    /**
     * The container file content is editor-controlled (`.soundcloud` is also a
     * regular upload extension), so the URL is re-validated on every read.
     *
     * @return string|null
     */
    public function getPublicUrl(File $file)
    {
        $parts = $this->parseSoundCloudUrl($this->getOnlineMediaId($file));

        return $parts !== null ? HttpUrlGuard::build($parts) : null;
    }

    /** @return string */
    public function getPreviewImage(File $file)
    {
        $url = (string)$this->getPublicUrl($file);
        if ($url === '') {
            return (string)GeneralUtility::getFileAbsFileName('EXT:mpc_vidply/Resources/Public/Icons/Extension.svg');
        }

        $cacheFile = $this->getTempFolderPath() . 'soundcloud_' . md5($url) . '.jpg';
        if (!file_exists($cacheFile)) {
            $oEmbed = $this->getOEmbedData($url);
            $thumbUrl = is_array($oEmbed) ? (string)($oEmbed['thumbnail_url'] ?? '') : '';
            if ($thumbUrl !== '' && $this->isSafeThumbnailUrl($thumbUrl)) {
                $image = $this->getRemoteContentFetcher()->fetchThumbnail($thumbUrl);
                if ($image !== null) {
                    GeneralUtility::writeFile($cacheFile, $image['binary'], true);
                }
            }
        }

        return file_exists($cacheFile)
            ? $cacheFile
            : (string)GeneralUtility::getFileAbsFileName('EXT:mpc_vidply/Resources/Public/Icons/Extension.svg');
    }

    /** @return array<string, mixed> */
    public function getMetaData(File $file)
    {
        $url = (string)$this->getPublicUrl($file);
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

    /**
     * Accepts the canonical host, its subdomains and the `on.soundcloud.com`
     * short-link host.
     *
     * @return array{scheme: string, host: string, port?: int, path?: string, query?: string, fragment?: string}|null
     */
    private function parseSoundCloudUrl(string $url): ?array
    {
        $parts = HttpUrlGuard::parse($url);
        if ($parts === null) {
            return null;
        }
        $host = $parts['host'];

        return $host === 'soundcloud.com' || str_ends_with($host, '.soundcloud.com') ? $parts : null;
    }

    private function isSafeThumbnailUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'https') {
            return false;
        }
        $host = strtolower((string)$parts['host']);
        return $host === 'sndcdn.com'
            || str_ends_with($host, '.sndcdn.com')
            || $host === 'soundcloud.com'
            || str_ends_with($host, '.soundcloud.com');
    }

    /** @return File|null */
    public function transformUrlToFile($url, Folder $targetFolder)
    {
        $parts = $this->parseSoundCloudUrl((string)$url);
        if ($parts === null) {
            return null;
        }

        $url = HttpUrlGuard::build($parts);
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
     * Fetch oEmbed metadata for a SoundCloud URL.
     *
     * SSRF boundary: this is the only outbound request driven by user input and
     * it is intentionally narrow. The request target is a fixed, hard-coded
     * SoundCloud endpoint; the user-supplied $url is never used as the request
     * host but only passed as a rawurlencode()'d query parameter. Callers reach
     * this method exclusively after {@see transformUrlToFile()} has constrained
     * $url to https/http on the soundcloud.com host family, and it only runs in
     * the TYPO3 backend (FAL online-media import / preview generation), never
     * from a frontend request. The thumbnail URL returned by oEmbed is
     * additionally re-validated against the sndcdn.com/soundcloud.com host
     * allow-list in {@see isSafeThumbnailUrl()} before it is fetched.
     *
     * @return array<string,mixed>|null
     */
    private function getOEmbedData(string $url): ?array
    {
        $oEmbedUrl = sprintf(
            'https://soundcloud.com/oembed?format=json&url=%s',
            rawurlencode($url)
        );
        $raw = (string)$this->getRemoteContentFetcher()->fetch($oEmbedUrl, 512 * 1024);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function getRemoteContentFetcher(): RemoteContentFetcher
    {
        return GeneralUtility::makeInstance(RemoteContentFetcher::class);
    }
}
