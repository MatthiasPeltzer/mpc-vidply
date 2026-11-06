<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Dto;

use Mpc\MpcVidply\Dto\StreamingConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamingConfiguration::class)]
final class StreamingConfigurationTest extends TestCase
{
    #[Test]
    public function emptyConfigurationFallsBackToDefaults(): void
    {
        $configuration = StreamingConfiguration::fromArray([]);

        self::assertFalse($configuration->enabled);
        self::assertSame('ffmpeg', $configuration->ffmpegPath);
        self::assertSame('ffprobe', $configuration->ffprobePath);
        self::assertSame('1:/user_upload/', $configuration->targetFolder);
        self::assertSame([1080, 720, 480, 360], $configuration->renditions);
        self::assertSame([480, 360], $configuration->signLanguageRenditions);
        self::assertSame(4, $configuration->segmentDuration);
        self::assertSame(0, $configuration->threads);
    }

    #[Test]
    public function configuredValuesAreTrimmedAndUsed(): void
    {
        $configuration = StreamingConfiguration::fromArray([
            'streamingEnabled' => '1',
            'ffmpegPath' => ' /usr/local/bin/ffmpeg ',
            'ffprobePath' => '/usr/local/bin/ffprobe',
            'streamingTargetFolder' => '2:/streams/',
            'streamingRenditions' => '720,480',
            'streamingSignLanguageRenditions' => '360',
            'streamingSegmentDuration' => '6',
            'streamingThreads' => '2',
        ]);

        self::assertTrue($configuration->enabled);
        self::assertSame('/usr/local/bin/ffmpeg', $configuration->ffmpegPath);
        self::assertSame('/usr/local/bin/ffprobe', $configuration->ffprobePath);
        self::assertSame('2:/streams/', $configuration->targetFolder);
        self::assertSame([720, 480], $configuration->renditions);
        self::assertSame([360], $configuration->signLanguageRenditions);
        self::assertSame(6, $configuration->segmentDuration);
        self::assertSame(2, $configuration->threads);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function segmentDurationProvider(): array
    {
        return [
            'zero' => ['0', 4],
            'negative' => ['-3', 4],
            'too long' => ['31', 4],
            'not a number' => ['abc', 4],
            'lower bound' => ['1', 1],
            'upper bound' => ['30', 30],
        ];
    }

    #[Test]
    #[DataProvider('segmentDurationProvider')]
    public function segmentDurationIsLimitedToOneToThirtySeconds(string $value, int $expected): void
    {
        $configuration = StreamingConfiguration::fromArray(['streamingSegmentDuration' => $value]);

        self::assertSame($expected, $configuration->segmentDuration);
    }

    #[Test]
    public function negativeThreadCountMeansAutomatic(): void
    {
        self::assertSame(0, StreamingConfiguration::fromArray(['streamingThreads' => '-4'])->threads);
    }

    /**
     * @return array<string, array{0: string, 1: list<int>}>
     */
    public static function heightsProvider(): array
    {
        return [
            'sorted highest first' => ['360, 1080,720', [1080, 720, 360]],
            'p suffix and mixed separators' => ['720p; 480P 360', [720, 480, 360]],
            'duplicates removed' => ['720,720p,480', [720, 480]],
            'odd heights rounded down to even' => ['721,481', [720, 480]],
            'out of range dropped' => ['100,480,5000', [480]],
            'garbage dropped' => ['hd,480,full', [480]],
            'nothing valid uses default' => ['foo, 99', [1080, 720, 480, 360]],
            'empty uses default' => ['', [1080, 720, 480, 360]],
        ];
    }

    /**
     * @param list<int> $expected
     */
    #[Test]
    #[DataProvider('heightsProvider')]
    public function parseHeightsNormalisesTheList(string $value, array $expected): void
    {
        self::assertSame($expected, StreamingConfiguration::parseHeights($value, StreamingConfiguration::DEFAULT_RENDITIONS));
    }

    #[Test]
    public function nonScalarValuesAreIgnored(): void
    {
        $configuration = StreamingConfiguration::fromArray([
            'ffmpegPath' => ['not', 'a', 'path'],
            'streamingRenditions' => new \stdClass(),
        ]);

        self::assertSame('ffmpeg', $configuration->ffmpegPath);
        self::assertSame(StreamingConfiguration::DEFAULT_RENDITIONS, $configuration->renditions);
    }
}
