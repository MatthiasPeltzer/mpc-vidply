<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service\Player;

use Mpc\MpcVidply\Service\Player\MediaFileRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Only the database-free helpers are exercised here; resolving real references
 * requires FAL and is covered by the functional suite. The subject is therefore
 * instantiated without its (service-heavy) constructor.
 */
final class MediaFileRegistryTest extends TestCase
{
    private MediaFileRegistry $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = (new \ReflectionClass(MediaFileRegistry::class))->newInstanceWithoutConstructor();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mimeTypeProvider(): array
    {
        return [
            'mp3' => ['https://cdn.example.com/a.mp3', 'audio/mpeg'],
            'ogg' => ['https://cdn.example.com/a.ogg', 'audio/ogg'],
            'wav' => ['https://cdn.example.com/a.wav', 'audio/wav'],
            'm3u8 hls' => ['https://cdn.example.com/a.m3u8', 'application/vnd.apple.mpegurl'],
            'mpd dash' => ['https://cdn.example.com/a.mpd', 'application/dash+xml'],
            'mp4' => ['https://cdn.example.com/a.mp4', 'video/mp4'],
            'webm' => ['https://cdn.example.com/a.webm', 'video/webm'],
            'unknown falls back to octet-stream' => ['https://cdn.example.com/a.bin', 'application/octet-stream'],
        ];
    }

    #[Test]
    #[DataProvider('mimeTypeProvider')]
    public function inferMimeTypeFromUrlMapsExtensions(string $url, string $expected): void
    {
        self::assertSame($expected, $this->subject->inferMimeTypeFromUrl($url, ''));
    }

    #[Test]
    public function inferMimeTypeFromUrlUsesFallbackForUnknownExtension(): void
    {
        self::assertSame(
            'video/quicktime',
            $this->subject->inferMimeTypeFromUrl('https://cdn.example.com/a.mov', 'video/quicktime')
        );
    }

    #[Test]
    public function getReferencesStaysAwayFromTheDatabaseForUnsavedRecords(): void
    {
        self::assertSame([], $this->subject->getReferences(0, 'media_file'));
    }
}
