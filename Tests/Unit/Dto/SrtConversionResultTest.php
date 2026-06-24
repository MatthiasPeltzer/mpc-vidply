<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Dto;

use Mpc\MpcVidply\Dto\SrtConversionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SrtConversionResult::class)]
final class SrtConversionResultTest extends TestCase
{
    #[Test]
    public function okFactoryMarksSuccessAndKeepsVtt(): void
    {
        $result = SrtConversionResult::ok("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHi\n");

        self::assertTrue($result->success);
        self::assertStringStartsWith("WEBVTT\n", $result->vtt);
        self::assertSame('', $result->errorMessage);
    }

    #[Test]
    public function failFactoryMarksFailureAndKeepsMessage(): void
    {
        $result = SrtConversionResult::fail('The subtitle file is empty.');

        self::assertFalse($result->success);
        self::assertSame('', $result->vtt);
        self::assertSame('The subtitle file is empty.', $result->errorMessage);
    }
}
