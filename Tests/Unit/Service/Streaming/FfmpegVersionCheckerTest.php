<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service\Streaming;

use Mpc\MpcVidply\Dto\ProcessResult;
use Mpc\MpcVidply\Service\Streaming\FfmpegVersionChecker;
use Mpc\MpcVidply\Service\Streaming\ProcessRunnerInterface;
use Mpc\MpcVidply\Service\Streaming\StreamingSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(FfmpegVersionChecker::class)]
final class FfmpegVersionCheckerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function versionOutputProvider(): array
    {
        return [
            'static release build' => ["ffmpeg version 8.0.1-static https://johnvansickle.com/ffmpeg/\nbuilt with gcc", '8.0.1'],
            'BtbN release branch build' => ['ffmpeg version n7.1.2-12-gabcdef0-20261101 Copyright (c) 2000-2026', '7.1.2'],
            'distribution package' => ['ffmpeg version 7.1.1-1+b1 Copyright (c) 2000-2025', '7.1.1'],
            'major.minor only' => ['ffprobe version 9.0 Copyright (c) 2007-2026', '9.0'],
            'git snapshot' => ['ffmpeg version N-121000-g0123456789 Copyright (c) 2000-2026', null],
            'not ffmpeg output' => ['command not found', null],
        ];
    }

    #[Test]
    #[DataProvider('versionOutputProvider')]
    public function parsesTheReleaseNumber(string $output, ?string $expected): void
    {
        self::assertSame($expected, FfmpegVersionChecker::parseVersion($output));
    }

    #[Test]
    public function checkRunsBothBinariesOnceAndCachesTheStatus(): void
    {
        $runner = $this->createMock(ProcessRunnerInterface::class);
        $runner->expects(self::exactly(2))
            ->method('run')
            ->willReturnCallback(static fn (array $command): ProcessResult => new ProcessResult(
                0,
                $command[0] . ' version 8.0.1 Copyright',
                ''
            ));

        $checker = new FfmpegVersionChecker($runner, $this->settings());
        $status = $checker->check();

        self::assertTrue($status->isUsable());
        self::assertSame('8.0.1', $status->ffmpegVersion);
        self::assertSame('8.0.1', $status->ffprobeVersion);
        self::assertSame($status, $checker->check());
    }

    #[Test]
    public function missingFfmpegIsReportedWithItsError(): void
    {
        $runner = $this->createMock(ProcessRunnerInterface::class);
        $runner->method('run')->willReturnCallback(static fn (array $command): ProcessResult => $command[0] === 'ffmpeg'
            ? new ProcessResult(127, '', 'sh: ffmpeg: not found')
            : new ProcessResult(0, 'ffprobe version 8.0.1', ''));

        $status = (new FfmpegVersionChecker($runner, $this->settings()))->check();

        self::assertFalse($status->ffmpegAvailable);
        self::assertFalse($status->isUsable());
        self::assertSame('sh: ffmpeg: not found', $status->error);
    }

    private function settings(): StreamingSettings
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);

        return new StreamingSettings($extensionConfiguration);
    }
}
