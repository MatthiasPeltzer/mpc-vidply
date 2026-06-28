<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service;

use Mpc\MpcVidply\Service\MediaFromUrlService;
use Mpc\MpcVidply\Service\MediaOEmbedMetadataService;
use Mpc\MpcVidply\Service\MediaUrlNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperInterface;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;

#[CoversClass(MediaFromUrlService::class)]
final class MediaFromUrlServiceTest extends TestCase
{
    #[Test]
    public function importReturnsActionableErrorForEmptyUrl(): void
    {
        $subject = new MediaFromUrlService(
            new MediaUrlNormalizer(),
            $this->createMock(OnlineMediaHelperRegistry::class),
            $this->createMock(ExtensionConfiguration::class),
            new MediaOEmbedMetadataService(),
        );

        $result = $subject->import('', $this->createMock(\TYPO3\CMS\Core\Resource\Folder::class));

        self::assertFalse($result->success);
        self::assertStringContainsString('invalid', strtolower($result->errorMessage));
    }

    #[Test]
    public function importReturnsAllowListHintForBlockedM3u8Url(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([
            'allowedVideoDomains' => '',
            'allowedAudioDomains' => '',
        ]);

        $subject = new MediaFromUrlService(
            new MediaUrlNormalizer(),
            $this->createMock(OnlineMediaHelperRegistry::class),
            $extensionConfiguration,
            new MediaOEmbedMetadataService(),
        );

        $result = $subject->import(
            'https://cdn.example.com/live/stream.m3u8',
            $this->createMock(\TYPO3\CMS\Core\Resource\Folder::class)
        );

        self::assertFalse($result->success);
        self::assertStringContainsString('allowedVideoDomains', $result->errorMessage);
    }

    #[Test]
    public function importMapsYoutubeFileToMediaTypeAndMetadata(): void
    {
        $previewPath = tempnam(sys_get_temp_dir(), 'vidply-poster-');
        self::assertNotFalse($previewPath);
        file_put_contents($previewPath, "\xFF\xD8\xFF\xD9");

        $file = $this->createMock(File::class);
        $file->method('getExtension')->willReturn('youtube');
        $file->method('getUid')->willReturn(7);
        $file->method('getName')->willReturn('Example clip.youtube');

        $posterFile = $this->createMock(File::class);
        $posterFile->method('getUid')->willReturn(99);

        $folder = $this->createMock(Folder::class);
        $folder->method('createFile')->willReturn($posterFile);

        $file->method('getParentFolder')->willReturn($folder);

        $helper = $this->createMock(OnlineMediaHelperInterface::class);
        $helper->method('getMetaData')->willReturn([
            'title' => 'Example clip',
            'author' => 'Example channel',
        ]);
        $helper->method('getPreviewImage')->willReturn($previewPath);
        $helper->method('getOnlineMediaId')->willReturn('');

        $registry = $this->createMock(OnlineMediaHelperRegistry::class);
        $registry->method('transformUrlToFile')->willReturn($file);
        $registry->method('getOnlineMediaHelper')->willReturn($helper);

        $subject = new MediaFromUrlService(
            new MediaUrlNormalizer(),
            $registry,
            $this->createMock(ExtensionConfiguration::class),
            new MediaOEmbedMetadataService(),
        );

        try {
            $result = $subject->import(
                'https://youtu.be/dQw4w9WgXcQ',
                $folder
            );

            self::assertTrue($result->success);
            self::assertSame('youtube', $result->mediaType);
            self::assertSame(7, $result->mediaFileUid);
            self::assertSame('Example clip', $result->title);
            self::assertSame('Example channel', $result->artist);
            self::assertSame(99, $result->posterFileUid);
            self::assertSame('YouTube', $result->detectedLabel);
        } finally {
            if (is_file($previewPath)) {
                unlink($previewPath);
            }
        }
    }
}
