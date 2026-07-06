<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service;

use Mpc\MpcVidply\Service\MediaOEmbedMetadataService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperInterface;

#[CoversClass(MediaOEmbedMetadataService::class)]
final class MediaOEmbedMetadataServiceTest extends TestCase
{
    #[Test]
    public function resolveForFileFallsBackToFileTitleWhenHelperMetadataOmitsTitle(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getProperty')->with('title')->willReturn('Stored clip title');
        $file->method('getName')->willReturn('Stored clip title.youtube');
        $file->method('getExtension')->willReturn('youtube');

        $helper = $this->createMock(OnlineMediaHelperInterface::class);
        $helper->method('getMetaData')->willReturn([
            'author' => 'Example channel',
        ]);
        $helper->method('getOnlineMediaId')->willReturn('');

        $subject = new MediaOEmbedMetadataService();
        $result = $subject->resolveForFile($file, $helper);

        self::assertSame('Stored clip title', $result['title']);
        self::assertSame('Example channel', $result['artist']);
    }

    #[Test]
    public function resolveForFileUsesHelperMetadataWhenAvailable(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getExtension')->willReturn('soundcloud');

        $helper = $this->createMock(OnlineMediaHelperInterface::class);
        $helper->method('getMetaData')->willReturn([
            'title' => 'Track title',
            'author' => 'Track artist',
        ]);
        $helper->method('getOnlineMediaId')->willReturn('');

        $subject = new MediaOEmbedMetadataService();
        $result = $subject->resolveForFile($file, $helper);

        self::assertSame('Track title', $result['title']);
        self::assertSame('Track artist', $result['artist']);
        self::assertSame('', $result['description']);
        self::assertSame(0, $result['duration']);
    }

    #[Test]
    public function parseSoundCloudDurationFromPageHtmlExtractsMilliseconds(): void
    {
        $html = '{"duration":213886,"title":"Example track"}';
        $duration = $this->invokePrivateMethod(
            new MediaOEmbedMetadataService(),
            'parseSoundCloudDurationFromPageHtml',
            [$html]
        );

        self::assertSame(214, $duration);
    }

    #[Test]
    public function parseSoundCloudDurationFromPageHtmlFallsBackToIso8601Meta(): void
    {
        $html = '<meta itemprop="duration" content="PT00H03M33S" />';
        $duration = $this->invokePrivateMethod(
            new MediaOEmbedMetadataService(),
            'parseSoundCloudDurationFromPageHtml',
            [$html]
        );

        self::assertSame(213, $duration);
    }

    #[Test]
    public function parseSoundCloudDescriptionFromPageHtmlExtractsOpenGraphDescription(): void
    {
        $html = '<meta property="og:description" content="From the Soulhack album." />';
        $description = $this->invokePrivateMethod(
            new MediaOEmbedMetadataService(),
            'parseSoundCloudDescriptionFromPageHtml',
            [$html]
        );

        self::assertSame('From the Soulhack album.', $description);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invokePrivateMethod(object $object, string $methodName, array $arguments): mixed
    {
        return (new \ReflectionMethod($object, $methodName))->invoke($object, ...$arguments);
    }
}
