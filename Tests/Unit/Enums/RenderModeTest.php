<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Enums;

use Mpc\MpcVidply\Enums\RenderMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RenderMode::class)]
final class RenderModeTest extends TestCase
{
    #[Test]
    public function backingValuesAreStable(): void
    {
        self::assertSame('privacy', RenderMode::Privacy->value);
        self::assertSame('mixedPlaylist', RenderMode::MixedPlaylist->value);
        self::assertSame('audio', RenderMode::Audio->value);
        self::assertSame('video', RenderMode::Video->value);
    }

    #[Test]
    public function fromResolvesKnownValuesAndTryFromRejectsUnknown(): void
    {
        self::assertSame(RenderMode::MixedPlaylist, RenderMode::from('mixedPlaylist'));
        self::assertNull(RenderMode::tryFrom('carousel'));
    }
}
