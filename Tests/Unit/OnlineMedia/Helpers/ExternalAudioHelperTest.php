<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\OnlineMedia\Helpers;

use Mpc\MpcVidply\OnlineMedia\Helpers\ExternalAudioHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;

#[CoversClass(ExternalAudioHelper::class)]
final class ExternalAudioHelperTest extends TestCase
{
    private function createSubject(string $allowedAudioDomains = 'cdn.example.com'): ExternalAudioHelper
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([
            'allowedAudioDomains' => $allowedAudioDomains,
        ]);

        return new ExternalAudioHelper('audio', $extensionConfiguration);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedUrlProvider(): array
    {
        return [
            'empty url' => [''],
            'missing scheme and host' => ['/relative/track.mp3'],
            'unsupported scheme' => ['ftp://cdn.example.com/track.mp3'],
            'unsupported extension' => ['https://cdn.example.com/track.ogg'],
            'video extension rejected' => ['https://cdn.example.com/track.mp4'],
            'disallowed host' => ['https://evil.example.org/track.mp3'],
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
            'getSize' => 36,
            'getContents' => 'https://cdn.example.com/audio/song.mp3',
        ]);

        self::assertSame(['title' => 'song.mp3'], $this->createSubject()->getMetaData($file));
    }
}
