<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\OnlineMedia\Helpers;

use Mpc\MpcVidply\OnlineMedia\Helpers\HlsHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;

#[CoversClass(HlsHelper::class)]
final class HlsHelperTest extends TestCase
{
    private function createSubject(string $allowedVideoDomains = 'cdn.example.com'): HlsHelper
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([
            'allowedVideoDomains' => $allowedVideoDomains,
        ]);

        return new HlsHelper('hls', $extensionConfiguration);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedUrlProvider(): array
    {
        return [
            'empty url' => [''],
            'missing scheme and host' => ['/stream/playlist.m3u8'],
            'unsupported scheme' => ['ftp://cdn.example.com/playlist.m3u8'],
            'non hls extension' => ['https://cdn.example.com/playlist.mpd'],
            'progressive extension' => ['https://cdn.example.com/video.mp4'],
            'disallowed host' => ['https://evil.example.org/playlist.m3u8'],
            'backslash host confusion' => ['https://evil.example.org\\@cdn.example.com/playlist.m3u8'],
            'userinfo' => ['https://user:pass@cdn.example.com/playlist.m3u8'],
            'embedded whitespace' => ['https://cdn.example.com/live stream/playlist.m3u8'],
        ];
    }

    #[Test]
    public function getPublicUrlReturnsAllowListedUrl(): void
    {
        $file = $this->createConfiguredMock(File::class, [
            'getUid' => 1,
            'getSize' => 40,
            'getContents' => 'https://cdn.example.com/live/master.m3u8',
        ]);

        self::assertSame('https://cdn.example.com/live/master.m3u8', $this->createSubject()->getPublicUrl($file));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeContainerContentProvider(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(document.domain)'],
            'disallowed host' => ['https://evil.example.org/master.m3u8'],
            'backslash host confusion' => ['https://evil.example.org\\@cdn.example.com/master.m3u8'],
            'userinfo' => ['https://user@cdn.example.com/master.m3u8'],
        ];
    }

    #[Test]
    #[DataProvider('unsafeContainerContentProvider')]
    public function getPublicUrlRejectsUnsafeContainerContent(string $content): void
    {
        $file = $this->createConfiguredMock(File::class, [
            'getUid' => 2,
            'getSize' => strlen($content),
            'getContents' => $content,
        ]);

        self::assertNull($this->createSubject()->getPublicUrl($file));
    }

    #[Test]
    #[DataProvider('rejectedUrlProvider')]
    public function transformUrlToFileRejectsInvalidInput(string $url): void
    {
        $folder = $this->createMock(Folder::class);

        self::assertNull($this->createSubject()->transformUrlToFile($url, $folder));
    }

    #[Test]
    public function getMetaDataExtractsFileNameFromUrl(): void
    {
        $file = $this->createConfiguredMock(File::class, [
            'getUid' => 1,
            'getSize' => 40,
            'getContents' => 'https://cdn.example.com/live/master.m3u8',
        ]);

        self::assertSame(['title' => 'master.m3u8'], $this->createSubject()->getMetaData($file));
    }
}
