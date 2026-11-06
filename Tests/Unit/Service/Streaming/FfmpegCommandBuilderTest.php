<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service\Streaming;

use Mpc\MpcVidply\Dto\StreamingConfiguration;
use Mpc\MpcVidply\Enums\StreamFormat;
use Mpc\MpcVidply\Service\Streaming\FfmpegCommandBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FfmpegCommandBuilder::class)]
final class FfmpegCommandBuilderTest extends TestCase
{
    private FfmpegCommandBuilder $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new FfmpegCommandBuilder();
    }

    /**
     * @return array<string, array{0: list<int>, 1: int, 2: list<int>}>
     */
    public static function heightSelectionProvider(): array
    {
        return [
            'full hd source keeps every rendition' => [[1080, 720, 480, 360], 1080, [1080, 720, 480, 360]],
            'never upscales' => [[1080, 720, 480, 360], 720, [720, 480, 360]],
            'odd source height counts as the even one below' => [[720, 480], 719, [480]],
            'source below every rendition uses its own height' => [[1080, 720], 300, [300]],
        ];
    }

    /**
     * @param list<int> $configured
     * @param list<int> $expected
     */
    #[Test]
    #[DataProvider('heightSelectionProvider')]
    public function selectHeightsNeverUpscales(array $configured, int $sourceHeight, array $expected): void
    {
        self::assertSame($expected, FfmpegCommandBuilder::selectHeights($configured, $sourceHeight));
    }

    #[Test]
    public function videoBitrateGrowsWithTheHeight(): void
    {
        self::assertSame(4950, FfmpegCommandBuilder::videoBitrate(1080));
        self::assertSame(2700, FfmpegCommandBuilder::videoBitrate(720));
        self::assertSame(1450, FfmpegCommandBuilder::videoBitrate(480));
        self::assertSame(950, FfmpegCommandBuilder::videoBitrate(360));
        self::assertSame(250, FfmpegCommandBuilder::videoBitrate(144));
        self::assertSame(200, FfmpegCommandBuilder::videoBitrate(2));
    }

    #[Test]
    public function manifestsAreNamedAfterTheSourceFile(): void
    {
        self::assertSame('mein-video.m3u8', FfmpegCommandBuilder::hlsManifestName('mein-video'));
        self::assertSame('mein-video.mpd', FfmpegCommandBuilder::dashManifestName('mein-video'));
    }

    #[Test]
    public function hlsCommandWritesFmp4VariantsWithAudioPerVariant(): void
    {
        $command = $this->subject->buildStreamCommand(
            $this->configuration(),
            StreamFormat::Hls,
            '/in/source.mp4',
            '/out/',
            'clip',
            [720, 480],
            true,
        );

        self::assertSame('ffmpeg', $command[0]);
        self::assertSame('/in/source.mp4', $this->valueAfter($command, '-i'));
        self::assertSame(
            '[0:v]split=2[s0][s1];[s0]scale=-2:720[v0];[s1]scale=-2:480[v1]',
            $this->valueAfter($command, '-filter_complex')
        );
        self::assertSame(['[v0]', '0:a:0', '[v1]', '0:a:0'], $this->valuesAfter($command, '-map'));
        self::assertSame('expr:gte(t,n_forced*4)', $this->valueAfter($command, '-force_key_frames'));
        self::assertSame('2700k', $this->valueAfter($command, '-b:v:0'));
        self::assertSame('1450k', $this->valueAfter($command, '-b:v:1'));
        self::assertSame('hls', $this->valueAfter($command, '-f'));
        self::assertSame('fmp4', $this->valueAfter($command, '-hls_segment_type'));
        self::assertSame('clip-%v-init.mp4', $this->valueAfter($command, '-hls_fmp4_init_filename'));
        self::assertSame('/out/clip-%v-%05d.m4s', $this->valueAfter($command, '-hls_segment_filename'));
        self::assertSame('clip.m3u8', $this->valueAfter($command, '-master_pl_name'));
        self::assertSame('v:0,a:0,name:720p v:1,a:1,name:480p', $this->valueAfter($command, '-var_stream_map'));
        self::assertSame('/out/clip-%v.m3u8', $command[array_key_last($command)]);
        self::assertNotContains('-threads', $command);
    }

    #[Test]
    public function silentSignLanguageHlsHasNoAudioMapping(): void
    {
        $command = $this->subject->buildStreamCommand(
            $this->configuration(),
            StreamFormat::Hls,
            '/in/sign.mp4',
            '/out/sign-dgs',
            'dgs',
            [480],
            false,
        );

        self::assertSame('[0:v]scale=-2:480[v0]', $this->valueAfter($command, '-filter_complex'));
        self::assertSame(['[v0]'], $this->valuesAfter($command, '-map'));
        self::assertContains('-an', $command);
        self::assertNotContains('-c:a', $command);
        self::assertSame('v:0,name:480p', $this->valueAfter($command, '-var_stream_map'));
    }

    #[Test]
    public function dashCommandSharesOneAudioTrack(): void
    {
        $command = $this->subject->buildStreamCommand(
            $this->configuration(threads: 3),
            StreamFormat::Dash,
            '/in/source.mp4',
            '/out',
            'clip',
            [720, 480],
            true,
        );

        self::assertSame(['[v0]', '[v1]', '0:a:0'], $this->valuesAfter($command, '-map'));
        self::assertSame('dash', $this->valueAfter($command, '-f'));
        self::assertSame('4', $this->valueAfter($command, '-seg_duration'));
        self::assertSame('id=0,streams=v id=1,streams=a', $this->valueAfter($command, '-adaptation_sets'));
        self::assertSame('clip-$RepresentationID$-init.$ext$', $this->valueAfter($command, '-init_seg_name'));
        self::assertSame('clip-$RepresentationID$-$Number%05d$.$ext$', $this->valueAfter($command, '-media_seg_name'));
        self::assertSame('3', $this->valueAfter($command, '-threads'));
        self::assertNotContains('-hls_playlist', $command);
        self::assertSame('/out/clip.mpd', $command[array_key_last($command)]);
    }

    #[Test]
    public function bothAddsAnHlsMasterToTheDashOutput(): void
    {
        $command = $this->subject->buildStreamCommand(
            $this->configuration(),
            StreamFormat::Both,
            '/in/source.mp4',
            '/out',
            'clip',
            [480],
            false,
        );

        self::assertSame('dash', $this->valueAfter($command, '-f'));
        self::assertSame('id=0,streams=v', $this->valueAfter($command, '-adaptation_sets'));
        self::assertSame('1', $this->valueAfter($command, '-hls_playlist'));
        self::assertSame('clip.m3u8', $this->valueAfter($command, '-hls_master_name'));
        self::assertSame('/out/clip.mpd', $command[array_key_last($command)]);
    }

    #[Test]
    public function mp4FallbackIsSilentAndFastStart(): void
    {
        $command = $this->subject->buildMp4FallbackCommand($this->configuration(), '/in/sign.mp4', '/out/dgs-480p.mp4', 480);

        self::assertSame('scale=-2:480', $this->valueAfter($command, '-vf'));
        self::assertSame('26', $this->valueAfter($command, '-crf'));
        self::assertSame('+faststart', $this->valueAfter($command, '-movflags'));
        self::assertContains('-an', $command);
        self::assertSame('/out/dgs-480p.mp4', $command[array_key_last($command)]);
    }

    #[Test]
    public function posterSeeksBeforeTheInputAndCapsTheWidth(): void
    {
        $command = $this->subject->buildPosterCommand($this->configuration(), '/in/source.mp4', '/out/clip-poster.jpg', 9.3456);

        self::assertSame('9.346', $this->valueAfter($command, '-ss'));
        self::assertLessThan(array_search('-i', $command, true), array_search('-ss', $command, true));
        self::assertSame('1', $this->valueAfter($command, '-frames:v'));
        self::assertSame('scale=\'min(1920,iw)\':-2', $this->valueAfter($command, '-vf'));
    }

    #[Test]
    public function posterTimestampIsNeverNegative(): void
    {
        $command = $this->subject->buildPosterCommand($this->configuration(), '/in/source.mp4', '/out/poster.jpg', -2.0);

        self::assertSame('0.000', $this->valueAfter($command, '-ss'));
    }

    private function configuration(int $threads = 0): StreamingConfiguration
    {
        return StreamingConfiguration::fromArray([
            'streamingEnabled' => '1',
            'streamingSegmentDuration' => '4',
            'streamingThreads' => (string)$threads,
        ]);
    }

    /**
     * @param list<string> $command
     */
    private function valueAfter(array $command, string $option): ?string
    {
        $index = array_search($option, $command, true);

        return $index === false ? null : ($command[$index + 1] ?? null);
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    private function valuesAfter(array $command, string $option): array
    {
        $values = [];
        foreach ($command as $index => $argument) {
            if ($argument === $option && isset($command[$index + 1])) {
                $values[] = $command[$index + 1];
            }
        }

        return $values;
    }
}
