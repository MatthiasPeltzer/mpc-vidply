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
        ];
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
