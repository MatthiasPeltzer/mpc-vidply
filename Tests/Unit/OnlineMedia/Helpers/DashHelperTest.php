<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\OnlineMedia\Helpers;

use Mpc\MpcVidply\OnlineMedia\Helpers\DashHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;

#[CoversClass(DashHelper::class)]
final class DashHelperTest extends TestCase
{
    private function createSubject(string $allowedVideoDomains = 'cdn.example.com'): DashHelper
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([
            'allowedVideoDomains' => $allowedVideoDomains,
        ]);

        return new DashHelper('dash', $extensionConfiguration);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedUrlProvider(): array
    {
        return [
            'empty url' => [''],
            'missing scheme and host' => ['/stream/manifest.mpd'],
            'unsupported scheme' => ['ftp://cdn.example.com/manifest.mpd'],
            'non dash extension' => ['https://cdn.example.com/playlist.m3u8'],
            'disallowed host' => ['https://evil.example.org/manifest.mpd'],
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
            'getContents' => 'https://cdn.example.com/live/manifest.mpd',
        ]);

        self::assertSame(['title' => 'manifest.mpd'], $this->createSubject()->getMetaData($file));
    }
}
