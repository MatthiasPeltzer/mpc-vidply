<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\OnlineMedia\Helpers;

use Mpc\MpcVidply\OnlineMedia\Helpers\SoundCloudHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\Folder;

#[CoversClass(SoundCloudHelper::class)]
final class SoundCloudHelperTest extends TestCase
{
    private SoundCloudHelper $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new SoundCloudHelper('soundcloud');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedUrlProvider(): array
    {
        return [
            'empty url' => [''],
            'missing scheme and host' => ['/tracks/song'],
            'unsupported scheme' => ['ftp://soundcloud.com/artist/track'],
            'foreign host' => ['https://example.com/artist/track'],
            'suffix trick host' => ['https://notsoundcloud.com/artist/track'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedUrlProvider')]
    public function transformUrlToFileRejectsInvalidInput(string $url): void
    {
        $folder = $this->createMock(Folder::class);

        self::assertNull($this->subject->transformUrlToFile($url, $folder));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function thumbnailUrlProvider(): array
    {
        return [
            'https sndcdn allowed' => ['https://i1.sndcdn.com/artwork.jpg', true],
            'https bare sndcdn allowed' => ['https://sndcdn.com/artwork.jpg', true],
            'https soundcloud allowed' => ['https://soundcloud.com/artwork.jpg', true],
            'http rejected' => ['http://i1.sndcdn.com/artwork.jpg', false],
            'foreign host rejected' => ['https://evil.example.com/artwork.jpg', false],
            'suffix trick rejected' => ['https://notsndcdn.com/artwork.jpg', false],
            'missing host rejected' => ['https:///artwork.jpg', false],
        ];
    }

    #[Test]
    #[DataProvider('thumbnailUrlProvider')]
    public function isSafeThumbnailUrlValidatesHost(string $url, bool $expected): void
    {
        $isSafe = (new \ReflectionMethod(SoundCloudHelper::class, 'isSafeThumbnailUrl'))
            ->invoke($this->subject, $url);

        self::assertSame($expected, $isSafe);
    }
}
