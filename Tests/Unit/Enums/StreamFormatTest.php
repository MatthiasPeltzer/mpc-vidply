<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Enums;

use Mpc\MpcVidply\Enums\StreamFormat;
use Mpc\MpcVidply\Enums\StreamStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamFormat::class)]
#[CoversClass(StreamStatus::class)]
final class StreamFormatTest extends TestCase
{
    /**
     * @return array<string, array{0: StreamFormat, 1: bool, 2: bool}>
     */
    public static function manifestProvider(): array
    {
        return [
            'hls' => [StreamFormat::Hls, true, false],
            'dash' => [StreamFormat::Dash, false, true],
            'both' => [StreamFormat::Both, true, true],
        ];
    }

    #[Test]
    #[DataProvider('manifestProvider')]
    public function formatsProduceTheirManifests(StreamFormat $format, bool $hls, bool $dash): void
    {
        self::assertSame($hls, $format->producesHls());
        self::assertSame($dash, $format->producesDash());
    }

    #[Test]
    public function unknownFormatValuesDefaultToHls(): void
    {
        self::assertSame(StreamFormat::Dash, StreamFormat::fromRecordValue('dash'));
        self::assertSame(StreamFormat::Both, StreamFormat::fromRecordValue('both'));
        self::assertSame(StreamFormat::Hls, StreamFormat::fromRecordValue(''));
        self::assertSame(StreamFormat::Hls, StreamFormat::fromRecordValue('webm'));
        self::assertSame(StreamFormat::Hls, StreamFormat::fromRecordValue(null));
    }

    #[Test]
    public function unknownStatusValuesMeanNeverQueued(): void
    {
        self::assertSame(StreamStatus::Pending, StreamStatus::fromRecordValue('pending'));
        self::assertSame(StreamStatus::Done, StreamStatus::fromRecordValue('done'));
        self::assertSame(StreamStatus::None, StreamStatus::fromRecordValue(''));
        self::assertSame(StreamStatus::None, StreamStatus::fromRecordValue('bogus'));
        self::assertSame(StreamStatus::None, StreamStatus::fromRecordValue(3));
    }
}
