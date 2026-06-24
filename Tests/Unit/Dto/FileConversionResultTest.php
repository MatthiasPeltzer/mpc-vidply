<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Dto;

use Mpc\MpcVidply\Dto\FileConversionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileConversionResult::class)]
final class FileConversionResultTest extends TestCase
{
    #[Test]
    public function convertedFactorySetsStatusAndNewFileName(): void
    {
        $result = FileConversionResult::converted('captions.srt', 'captions.vtt');

        self::assertSame(FileConversionResult::STATUS_CONVERTED, $result->status);
        self::assertSame('captions.srt', $result->originalFileName);
        self::assertSame('captions.vtt', $result->newFileName);
        self::assertSame('', $result->message);
    }

    #[Test]
    public function skippedFactorySetsStatusAndMessage(): void
    {
        $result = FileConversionResult::skipped('clip.vtt', 'File is not an SRT subtitle.');

        self::assertSame(FileConversionResult::STATUS_SKIPPED, $result->status);
        self::assertSame('clip.vtt', $result->originalFileName);
        self::assertSame('File is not an SRT subtitle.', $result->message);
        self::assertSame('', $result->newFileName);
    }

    #[Test]
    public function failedFactorySetsStatusAndMessage(): void
    {
        $result = FileConversionResult::failed('broken.srt', 'No valid cues.');

        self::assertSame(FileConversionResult::STATUS_FAILED, $result->status);
        self::assertSame('broken.srt', $result->originalFileName);
        self::assertSame('No valid cues.', $result->message);
        self::assertSame('', $result->newFileName);
    }
}
