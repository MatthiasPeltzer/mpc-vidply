<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Dto;

use Mpc\MpcVidply\Dto\FfmpegStatus;
use Mpc\MpcVidply\Dto\ProcessResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FfmpegStatus::class)]
#[CoversClass(ProcessResult::class)]
final class FfmpegStatusTest extends TestCase
{
    /**
     * @return array<string, array{0: bool, 1: bool, 2: ?string, 3: bool, 4: bool, 5: bool}>
     */
    public static function statusProvider(): array
    {
        // ffmpegAvailable, ffprobeAvailable, version => usable, tooOld, unknownVersion
        return [
            'current release' => [true, true, '8.0.1', true, false, false],
            'exactly the minimum' => [true, true, '7.1', true, false, false],
            'too old' => [true, true, '6.1.2', false, true, false],
            'git snapshot without release number' => [true, true, null, true, false, true],
            'ffmpeg missing' => [false, true, null, false, false, false],
            'ffprobe missing' => [true, false, '8.0', false, false, false],
        ];
    }

    #[Test]
    #[DataProvider('statusProvider')]
    public function reportsUsabilityAndVersionState(
        bool $ffmpegAvailable,
        bool $ffprobeAvailable,
        ?string $version,
        bool $usable,
        bool $tooOld,
        bool $unknownVersion,
    ): void {
        $status = new FfmpegStatus('ffmpeg', 'ffprobe', $ffmpegAvailable, $ffprobeAvailable, $version, $version, '7.1');

        self::assertSame($usable, $status->isUsable());
        self::assertSame($tooOld, $status->isTooOld());
        self::assertSame($unknownVersion, $status->isVersionUnknown());
    }

    #[Test]
    public function processResultReturnsTheLastErrorLines(): void
    {
        $result = new ProcessResult(1, '', "line 1\nline 2\r\nline 3\n");

        self::assertFalse($result->isSuccessful());
        self::assertSame("line 2\nline 3", $result->errorTail(2));
        self::assertSame("line 1\nline 2\nline 3", $result->errorTail());
    }
}
