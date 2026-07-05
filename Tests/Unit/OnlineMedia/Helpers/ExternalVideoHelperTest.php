<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\OnlineMedia\Helpers;

use Mpc\MpcVidply\OnlineMedia\Helpers\ExternalVideoHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;

#[CoversClass(ExternalVideoHelper::class)]
final class ExternalVideoHelperTest extends TestCase
{
    private function createSubject(string $allowedVideoDomains = 'cdn.example.com'): ExternalVideoHelper
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([
            'allowedVideoDomains' => $allowedVideoDomains,
        ]);

        return new ExternalVideoHelper('video', $extensionConfiguration);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedUrlProvider(): array
    {
        return [
            'empty url' => [''],
            'whitespace only' => ['   '],
            'missing scheme and host' => ['/relative/video.mp4'],
            'unsupported scheme' => ['ftp://cdn.example.com/video.mp4'],
            'javascript scheme' => ['javascript:alert(1)'],
            'unsupported extension' => ['https://cdn.example.com/video.mkv'],
            'no extension' => ['https://cdn.example.com/video'],
            'disallowed host' => ['https://evil.example.org/video.mp4'],
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
    public function transformUrlToFileRejectsWhenNoDomainsConfigured(): void
    {
        $folder = $this->createMock(Folder::class);

        self::assertNull(
            $this->createSubject('')->transformUrlToFile('https://cdn.example.com/video.mp4', $folder)
        );
    }

    #[Test]
    public function getMetaDataExtractsFileNameFromUrl(): void
    {
        $file = $this->createConfiguredMock(File::class, [
            'getUid' => 1,
            'getSize' => 36,
            'getContents' => 'https://cdn.example.com/path/to/clip.mp4',
        ]);

        self::assertSame(['title' => 'clip.mp4'], $this->createSubject()->getMetaData($file));
    }

    #[Test]
    public function getMetaDataReturnsEmptyArrayWhenIdMissing(): void
    {
        $file = $this->createConfiguredMock(File::class, [
            'getUid' => 2,
            'getSize' => 0,
            'getContents' => '',
        ]);

        self::assertSame([], $this->createSubject()->getMetaData($file));
    }

    #[Test]
    public function getPublicUrlReturnsStoredId(): void
    {
        $file = $this->createConfiguredMock(File::class, [
            'getUid' => 3,
            'getSize' => 40,
            'getContents' => 'https://cdn.example.com/path/to/clip.mp4',
        ]);

        self::assertSame('https://cdn.example.com/path/to/clip.mp4', $this->createSubject()->getPublicUrl($file));
    }
}
