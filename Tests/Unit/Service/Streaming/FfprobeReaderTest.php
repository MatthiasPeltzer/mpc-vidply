<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service\Streaming;

use Mpc\MpcVidply\Service\Streaming\FfprobeReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FfprobeReader::class)]
final class FfprobeReaderTest extends TestCase
{
    #[Test]
    public function readsDimensionsDurationAndAudio(): void
    {
        $result = FfprobeReader::parse((string)json_encode([
            'streams' => [
                ['codec_type' => 'video', 'width' => 1920, 'height' => 1080],
                ['codec_type' => 'audio'],
            ],
            'format' => ['duration' => '93.48'],
        ]));

        self::assertSame(1920, $result->width);
        self::assertSame(1080, $result->height);
        self::assertSame(93.48, $result->duration);
        self::assertTrue($result->hasAudio);
    }

    #[Test]
    public function silentVideoHasNoAudio(): void
    {
        $result = FfprobeReader::parse((string)json_encode([
            'streams' => [['codec_type' => 'video', 'width' => 640, 'height' => 480, 'duration' => '12.0']],
        ]));

        self::assertFalse($result->hasAudio);
        self::assertSame(12.0, $result->duration);
    }

    #[Test]
    public function skipsCoverArtStreams(): void
    {
        $result = FfprobeReader::parse((string)json_encode([
            'streams' => [
                ['codec_type' => 'video', 'width' => 600, 'height' => 600, 'disposition' => ['attached_pic' => 1]],
                ['codec_type' => 'video', 'width' => 1280, 'height' => 720],
            ],
        ]));

        self::assertSame(1280, $result->width);
        self::assertSame(720, $result->height);
    }

    #[Test]
    public function swapsDimensionsOfRotatedPhoneVideos(): void
    {
        $sideData = FfprobeReader::parse((string)json_encode([
            'streams' => [[
                'codec_type' => 'video',
                'width' => 1920,
                'height' => 1080,
                'side_data_list' => [['side_data_type' => 'Display Matrix', 'rotation' => -90]],
            ]],
        ]));
        $legacyTag = FfprobeReader::parse((string)json_encode([
            'streams' => [['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'tags' => ['rotate' => '270']]],
        ]));
        $upsideDown = FfprobeReader::parse((string)json_encode([
            'streams' => [['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'tags' => ['rotate' => '180']]],
        ]));

        self::assertSame([1080, 1920], [$sideData->width, $sideData->height]);
        self::assertSame([1080, 1920], [$legacyTag->width, $legacyTag->height]);
        self::assertSame([1920, 1080], [$upsideDown->width, $upsideDown->height]);
    }

    #[Test]
    public function rejectsInvalidJson(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1762156802);

        FfprobeReader::parse('not json');
    }

    #[Test]
    public function rejectsFilesWithoutVideo(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1762156803);

        FfprobeReader::parse((string)json_encode(['streams' => [['codec_type' => 'audio']]]));
    }

    #[Test]
    public function rejectsVideoWithoutDimensions(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1762156804);

        FfprobeReader::parse((string)json_encode(['streams' => [['codec_type' => 'video']]]));
    }
}
