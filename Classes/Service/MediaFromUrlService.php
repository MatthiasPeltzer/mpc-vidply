<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use Mpc\MpcVidply\Dto\MediaImportResult;
use Mpc\MpcVidply\Enums\MediaType;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException;
use TYPO3\CMS\Core\Resource\Exception\OnlineMediaAlreadyExistsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperInterface;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class MediaFromUrlService
{
    /** @var array<string, MediaType> */
    private const EXTENSION_TO_MEDIA_TYPE = [
        'youtube' => MediaType::YouTube,
        'vimeo' => MediaType::Vimeo,
        'soundcloud' => MediaType::SoundCloud,
        'externalaudio' => MediaType::Audio,
        'hls' => MediaType::Video,
        'dash' => MediaType::Video,
        'externalvideo' => MediaType::Video,
    ];

    /** @var array<string, string> */
    private const EXTENSION_TO_LABEL = [
        'youtube' => 'YouTube',
        'vimeo' => 'Vimeo',
        'soundcloud' => 'SoundCloud',
        'externalaudio' => 'External audio',
        'hls' => 'HLS stream',
        'dash' => 'DASH stream',
        'externalvideo' => 'External video',
    ];

    public function __construct(
        private readonly MediaUrlNormalizer $urlNormalizer,
        private readonly OnlineMediaHelperRegistry $onlineMediaHelperRegistry,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly MediaOEmbedMetadataService $oEmbedMetadataService,
    ) {}

    public function import(string $url, Folder $targetFolder): MediaImportResult
    {
        $normalizedUrl = $this->urlNormalizer->normalize($url);
        if ($normalizedUrl === null) {
            return MediaImportResult::fail(
                'The URL is empty or invalid. Paste a full http(s) link to a supported media source.'
            );
        }

        $file = $this->transformUrlToFile($normalizedUrl, $targetFolder);
        if ($file === null) {
            return MediaImportResult::fail($this->buildFailureMessage($normalizedUrl));
        }

        return $this->buildResultFromFile($file, $normalizedUrl);
    }

    /**
     * Re-fetch oEmbed/metadata for an existing online-media sys_file.
     */
    public function refreshMetadata(File $file, ?MediaType $currentMediaType = null): MediaImportResult
    {
        $helper = $this->onlineMediaHelperRegistry->getOnlineMediaHelper($file);
        if (!$helper instanceof OnlineMediaHelperInterface) {
            return MediaImportResult::fail('This file is not an online-media reference that supports metadata import.');
        }

        $detectedType = $this->resolveMediaType($file);
        if ($detectedType === null) {
            return MediaImportResult::fail('Could not determine the media type for this file.');
        }

        $metadata = $this->oEmbedMetadataService->resolveForFile($file, $helper);

        $thumbnailUrl = $this->resolvePosterThumbnailUrl($file, $helper, $metadata['thumbnailUrl'], null);
        $posterFileUid = $this->resolvePosterFileUid(
            $helper,
            $file,
            $file->getParentFolder(),
            $thumbnailUrl,
        );

        $typeMismatchWarning = null;
        if ($currentMediaType !== null && $currentMediaType !== $detectedType) {
            $typeMismatchWarning = sprintf(
                'The URL matches type "%s", but this record is set to "%s". The media type was not changed.',
                $detectedType->value,
                $currentMediaType->value
            );
        }

        return new MediaImportResult(
            success: true,
            mediaType: $detectedType->value,
            mediaFileUid: $file->getUid(),
            title: $metadata['title'],
            artist: $metadata['artist'],
            description: $metadata['description'],
            duration: $metadata['duration'],
            posterFileUid: $posterFileUid,
            detectedLabel: self::EXTENSION_TO_LABEL[$file->getExtension()] ?? $file->getExtension(),
            typeMismatchWarning: $typeMismatchWarning,
        );
    }

    private function transformUrlToFile(string $url, Folder $targetFolder): ?File
    {
        try {
            $file = $this->onlineMediaHelperRegistry->transformUrlToFile($url, $targetFolder, []);
            return $file instanceof File ? $file : null;
        } catch (OnlineMediaAlreadyExistsException $exception) {
            $existing = $exception->getOnlineMedia();
            return $existing instanceof File ? $existing : null;
        }
    }

    private function buildResultFromFile(File $file, ?string $sourceUrl = null): MediaImportResult
    {
        $mediaType = $this->resolveMediaType($file);
        if ($mediaType === null) {
            return MediaImportResult::fail('The URL was recognized but the media type could not be mapped.');
        }

        $helper = $this->onlineMediaHelperRegistry->getOnlineMediaHelper($file);
        $metadata = $this->oEmbedMetadataService->resolveForFile(
            $file,
            $helper instanceof OnlineMediaHelperInterface ? $helper : null
        );

        $posterFileUid = 0;
        if ($helper instanceof OnlineMediaHelperInterface) {
            $thumbnailUrl = $this->resolvePosterThumbnailUrl(
                $file,
                $helper,
                $metadata['thumbnailUrl'],
                $sourceUrl,
            );
            $posterFileUid = $this->resolvePosterFileUid(
                $helper,
                $file,
                $file->getParentFolder(),
                $thumbnailUrl,
            );
        }

        $extension = $file->getExtension();

        return MediaImportResult::ok(
            mediaType: $mediaType->value,
            mediaFileUid: $file->getUid(),
            title: $metadata['title'],
            artist: $metadata['artist'],
            description: $metadata['description'],
            duration: $metadata['duration'],
            posterFileUid: $posterFileUid,
            detectedLabel: self::EXTENSION_TO_LABEL[$extension] ?? $extension,
        );
    }

    private function resolveMediaType(File $file): ?MediaType
    {
        return self::EXTENSION_TO_MEDIA_TYPE[$file->getExtension()] ?? null;
    }

    private function resolvePosterFileUid(
        OnlineMediaHelperInterface $helper,
        File $file,
        Folder $targetFolder,
        string $thumbnailUrl,
    ): int {
        $previewPath = (string)$helper->getPreviewImage($file);
        if ($previewPath !== '' && is_file($previewPath)) {
            $binary = file_get_contents($previewPath);
            if (is_string($binary) && $binary !== '') {
                $posterFile = $this->importPosterBinary($targetFolder, $binary, $file);
                if ($posterFile instanceof File) {
                    return $posterFile->getUid();
                }
            }
        }

        if ($thumbnailUrl !== '') {
            $binary = GeneralUtility::getUrl($thumbnailUrl);
            if (is_string($binary) && $binary !== '') {
                $posterFile = $this->importPosterBinary($targetFolder, $binary, $file);
                if ($posterFile instanceof File) {
                    return $posterFile->getUid();
                }
            }
        }

        return 0;
    }

    private function resolvePosterThumbnailUrl(
        File $file,
        OnlineMediaHelperInterface $helper,
        string $metadataThumbnailUrl,
        ?string $sourceUrl,
    ): string {
        if ($metadataThumbnailUrl !== '') {
            return $metadataThumbnailUrl;
        }

        $mediaId = trim((string)$helper->getOnlineMediaId($file));
        if ($mediaId === '' && $sourceUrl !== null) {
            $mediaId = $this->urlNormalizer->extractYouTubeId($sourceUrl)
                ?? $this->urlNormalizer->extractVimeoId($sourceUrl)
                ?? '';
        }

        if ($mediaId === '') {
            return '';
        }

        return match ($file->getExtension()) {
            'youtube' => sprintf(
                'https://i.ytimg.com/vi/%s/hqdefault.jpg',
                rawurlencode($mediaId)
            ),
            'vimeo' => sprintf(
                'https://vumbnail.com/%s.jpg',
                rawurlencode($mediaId)
            ),
            default => '',
        };
    }

    private function importPosterBinary(Folder $targetFolder, string $binary, File $sourceFile): ?File
    {
        if ($binary === '') {
            return null;
        }

        $baseName = $this->buildPosterBaseName($sourceFile);
        $extension = 'jpg';

        for ($attempt = 1; $attempt <= 20; ++$attempt) {
            $fileName = $attempt === 1
                ? $baseName . '-poster.' . $extension
                : $baseName . '-poster_' . $attempt . '.' . $extension;

            try {
                $posterFile = $targetFolder->createFile($fileName);
                $posterFile->setContents($binary);

                return $posterFile;
            } catch (ExistingTargetFileNameException) {
                continue;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function buildPosterBaseName(File $file): string
    {
        $baseName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo($file->getName(), PATHINFO_FILENAME)) ?? 'poster';
        $baseName = trim((string)$baseName, '._-');

        return $baseName !== '' ? $baseName : 'poster';
    }

    private function buildFailureMessage(string $url): string
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
        $path = is_array($parts) ? strtolower((string)($parts['path'] ?? '')) : '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($this->urlNormalizer->extractYouTubeId($url) !== null) {
            return 'YouTube import failed. Check that the video URL is valid and reachable.';
        }

        if ($this->urlNormalizer->extractVimeoId($url) !== null) {
            return 'Vimeo import failed. Check that the video URL is valid and reachable.';
        }

        if (str_contains($host, 'soundcloud.com') || $host === 'on.soundcloud.com') {
            return 'SoundCloud import failed. Use a public track or playlist URL on soundcloud.com.';
        }

        if ($extension === 'm3u8' || str_ends_with($path, '.m3u8')) {
            return $this->buildDomainAllowListMessage('allowedVideoDomains', 'HLS (.m3u8) streaming');
        }

        if ($extension === 'mpd' || str_ends_with($path, '.mpd')) {
            return $this->buildDomainAllowListMessage('allowedVideoDomains', 'DASH (.mpd) streaming');
        }

        if (in_array($extension, ['mp4', 'webm', 'ogv', 'm4v'], true)) {
            return $this->buildDomainAllowListMessage('allowedVideoDomains', 'external MP4/WebM video');
        }

        if (in_array($extension, ['mp3', 'ogg', 'm4a', 'aac', 'flac', 'oga', 'wav'], true)) {
            return $this->buildDomainAllowListMessage('allowedAudioDomains', 'external audio');
        }

        return 'Unsupported URL. Supported sources: YouTube, Vimeo, SoundCloud, .m3u8, .mpd, and allowlisted MP4/MP3 URLs.';
    }

    private function buildDomainAllowListMessage(string $configKey, string $sourceLabel): string
    {
        try {
            $configured = trim((string)($this->extensionConfiguration->get('mpc_vidply')[$configKey] ?? ''));
        } catch (\Throwable) {
            $configured = '';
        }

        if ($configured === '') {
            return sprintf(
                '%s URLs require an allow-list. Configure "%s" in Admin Tools → Settings → Extension Configuration → mpc_vidply.',
                $sourceLabel,
                $configKey
            );
        }

        return sprintf(
            '%s URL rejected. The host is not in the configured "%s" allow-list (Extension Configuration → mpc_vidply).',
            $sourceLabel,
            $configKey
        );
    }
}
