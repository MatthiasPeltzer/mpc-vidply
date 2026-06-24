<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Dto;

use Mpc\MpcVidply\Dto\FileConversionResult;
use Mpc\MpcVidply\Dto\MigrationBatchResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationBatchResult::class)]
final class MigrationBatchResultTest extends TestCase
{
    #[Test]
    public function emptyBatchCountsAreZero(): void
    {
        $batch = new MigrationBatchResult([]);

        self::assertSame(0, $batch->convertedCount());
        self::assertSame(0, $batch->skippedCount());
        self::assertSame(0, $batch->failedCount());
    }

    #[Test]
    public function countsAreGroupedByStatus(): void
    {
        $batch = new MigrationBatchResult([
            FileConversionResult::converted('a.srt', 'a.vtt'),
            FileConversionResult::converted('b.srt', 'b.vtt'),
            FileConversionResult::skipped('c.vtt', 'already vtt'),
            FileConversionResult::failed('d.srt', 'boom'),
            FileConversionResult::failed('e.srt', 'boom'),
            FileConversionResult::failed('f.srt', 'boom'),
        ]);

        self::assertSame(2, $batch->convertedCount());
        self::assertSame(1, $batch->skippedCount());
        self::assertSame(3, $batch->failedCount());
    }

    #[Test]
    public function countByStatusHandlesUnknownStatus(): void
    {
        $batch = new MigrationBatchResult([
            FileConversionResult::converted('a.srt', 'a.vtt'),
        ]);

        self::assertSame(0, $batch->countByStatus('nonexistent'));
    }
}
