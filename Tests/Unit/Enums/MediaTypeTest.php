<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Enums;

use Mpc\MpcVidply\Enums\MediaType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MediaType::class)]
final class MediaTypeTest extends TestCase
{
    /**
     * @return array<string, array{0: MediaType, 1: bool}>
     */
    public static function isExternalProvider(): array
    {
        return [
            'video is local' => [MediaType::Video, false],
            'audio is local' => [MediaType::Audio, false],
            'youtube is external' => [MediaType::YouTube, true],
            'vimeo is external' => [MediaType::Vimeo, true],
            'soundcloud is external' => [MediaType::SoundCloud, true],
        ];
    }

    #[Test]
    #[DataProvider('isExternalProvider')]
    public function isExternalReflectsServiceType(MediaType $type, bool $expected): void
    {
        self::assertSame($expected, $type->isExternal());
    }

    /**
     * @return array<string, array{0: MediaType, 1: bool}>
     */
    public static function isAudioOnlyProvider(): array
    {
        return [
            'video has a video track' => [MediaType::Video, false],
            'audio is audio only' => [MediaType::Audio, true],
            'youtube has a video track' => [MediaType::YouTube, false],
            'vimeo has a video track' => [MediaType::Vimeo, false],
            'soundcloud is audio only' => [MediaType::SoundCloud, true],
        ];
    }

    #[Test]
    #[DataProvider('isAudioOnlyProvider')]
    public function isAudioOnlyReflectsTrackType(MediaType $type, bool $expected): void
    {
        self::assertSame($expected, $type->isAudioOnly());
    }

    #[Test]
    public function backingValuesMatchDatabaseColumn(): void
    {
        self::assertSame('video', MediaType::Video->value);
        self::assertSame('audio', MediaType::Audio->value);
        self::assertSame('youtube', MediaType::YouTube->value);
        self::assertSame('vimeo', MediaType::Vimeo->value);
        self::assertSame('soundcloud', MediaType::SoundCloud->value);
        self::assertSame(MediaType::YouTube, MediaType::from('youtube'));
        self::assertNull(MediaType::tryFrom('unknown'));
    }
}
