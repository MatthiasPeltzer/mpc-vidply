<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Streaming;

use Mpc\MpcVidply\Dto\StreamingConfiguration;
use Mpc\MpcVidply\Enums\StreamFormat;

/**
 * Builds ffmpeg argument lists for adaptive-streaming output.
 *
 * Every format writes fMP4 (CMAF) segments with keyframes forced on segment
 * boundaries, so "both" serves one set of segments from a DASH and an HLS
 * manifest. Renditions are H.264 (High, yuv420p) with stereo AAC.
 */
final class FfmpegCommandBuilder
{
    private const AUDIO_BITRATE = '128k';
    private const POSTER_MAX_WIDTH = 1920;

    public static function hlsManifestName(string $baseName): string
    {
        return $baseName . '.m3u8';
    }

    public static function dashManifestName(string $baseName): string
    {
        return $baseName . '.mpd';
    }

    /**
     * Configured heights the source can fill; never upscales. When the source
     * is smaller than every configured height, its own (even) height is used.
     *
     * @param list<int> $configuredHeights Highest first
     * @return list<int>
     */
    public static function selectHeights(array $configuredHeights, int $sourceHeight): array
    {
        $sourceHeight -= $sourceHeight % 2;
        $heights = array_values(array_filter($configuredHeights, static fn (int $height): bool => $height <= $sourceHeight));

        return $heights !== [] ? $heights : [max(2, $sourceHeight)];
    }

    /**
     * Target video bitrate in kbit/s: grows with the pixel count but less
     * than linearly (≈ 4950 at 1080p, 2700 at 720p, 1450 at 480p, 950 at 360p).
     */
    public static function videoBitrate(int $height): int
    {
        return max(200, (int)(round($height ** 1.5 * 0.14 / 50) * 50));
    }

    /**
     * @param list<int> $heights Renditions, highest first
     * @return list<string>
     */
    public function buildStreamCommand(
        StreamingConfiguration $configuration,
        StreamFormat $format,
        string $inputPath,
        string $outputDirectory,
        string $baseName,
        array $heights,
        bool $withAudio,
    ): array {
        $outputDirectory = rtrim($outputDirectory, '/');
        $command = [
            $configuration->ffmpegPath,
            '-hide_banner', '-nostdin', '-nostats', '-loglevel', 'error', '-y',
            '-i', $inputPath,
            '-filter_complex', self::scaleFilter($heights),
        ];

        $count = count($heights);
        $perVariantAudio = $withAudio && $format === StreamFormat::Hls;
        for ($index = 0; $index < $count; $index++) {
            $command[] = '-map';
            $command[] = '[v' . $index . ']';
            if ($perVariantAudio) {
                $command[] = '-map';
                $command[] = '0:a:0';
            }
        }
        if ($withAudio && !$perVariantAudio) {
            $command[] = '-map';
            $command[] = '0:a:0';
        }

        array_push(
            $command,
            '-c:v',
            'libx264',
            '-preset',
            'fast',
            '-profile:v',
            'high',
            '-pix_fmt',
            'yuv420p',
            '-sc_threshold',
            '0',
            '-force_key_frames',
            'expr:gte(t,n_forced*' . $configuration->segmentDuration . ')',
        );
        foreach ($heights as $index => $height) {
            $bitrate = self::videoBitrate($height);
            array_push(
                $command,
                '-b:v:' . $index,
                $bitrate . 'k',
                '-maxrate:v:' . $index,
                (int)round($bitrate * 1.07) . 'k',
                '-bufsize:v:' . $index,
                (int)round($bitrate * 1.5) . 'k',
            );
        }
        if ($withAudio) {
            array_push($command, '-c:a', 'aac', '-b:a', self::AUDIO_BITRATE, '-ac', '2', '-ar', '48000');
        } else {
            $command[] = '-an';
        }
        if ($configuration->threads > 0) {
            array_push($command, '-threads', (string)$configuration->threads);
        }

        if ($format === StreamFormat::Hls) {
            $variantMap = [];
            foreach ($heights as $index => $height) {
                $variantMap[] = 'v:' . $index . ($withAudio ? ',a:' . $index : '') . ',name:' . $height . 'p';
            }
            array_push(
                $command,
                '-f',
                'hls',
                '-hls_time',
                (string)$configuration->segmentDuration,
                '-hls_playlist_type',
                'vod',
                '-hls_list_size',
                '0',
                '-hls_segment_type',
                'fmp4',
                '-hls_flags',
                'independent_segments',
                '-hls_fmp4_init_filename',
                $baseName . '-%v-init.mp4',
                '-hls_segment_filename',
                $outputDirectory . '/' . $baseName . '-%v-%05d.m4s',
                '-master_pl_name',
                self::hlsManifestName($baseName),
                '-var_stream_map',
                implode(' ', $variantMap),
                $outputDirectory . '/' . $baseName . '-%v.m3u8',
            );

            return $command;
        }

        array_push(
            $command,
            '-f',
            'dash',
            '-seg_duration',
            (string)$configuration->segmentDuration,
            '-use_template',
            '1',
            '-use_timeline',
            '1',
            '-adaptation_sets',
            $withAudio ? 'id=0,streams=v id=1,streams=a' : 'id=0,streams=v',
            '-init_seg_name',
            $baseName . '-$RepresentationID$-init.$ext$',
            '-media_seg_name',
            $baseName . '-$RepresentationID$-$Number%05d$.$ext$',
        );
        if ($format === StreamFormat::Both) {
            array_push($command, '-hls_playlist', '1', '-hls_master_name', self::hlsManifestName($baseName));
        }
        $command[] = $outputDirectory . '/' . self::dashManifestName($baseName);

        return $command;
    }

    /**
     * Progressive MP4 for players without MSE (and as the error fallback):
     * one rendition, no audio, moov atom first for fast start.
     *
     * @return list<string>
     */
    public function buildMp4FallbackCommand(
        StreamingConfiguration $configuration,
        string $inputPath,
        string $outputPath,
        int $height,
    ): array {
        $command = [
            $configuration->ffmpegPath,
            '-hide_banner', '-nostdin', '-nostats', '-loglevel', 'error', '-y',
            '-i', $inputPath,
            '-map', '0:v:0',
            '-vf', 'scale=-2:' . $height,
            '-c:v', 'libx264',
            '-preset', 'fast',
            '-crf', '26',
            '-profile:v', 'high',
            '-pix_fmt', 'yuv420p',
            '-an',
            '-movflags', '+faststart',
        ];
        if ($configuration->threads > 0) {
            array_push($command, '-threads', (string)$configuration->threads);
        }
        $command[] = $outputPath;

        return $command;
    }

    /**
     * Single JPEG frame at `$seconds`, at most 1920 px wide.
     *
     * @return list<string>
     */
    public function buildPosterCommand(
        StreamingConfiguration $configuration,
        string $inputPath,
        string $outputPath,
        float $seconds,
    ): array {
        return [
            $configuration->ffmpegPath,
            '-hide_banner', '-nostdin', '-nostats', '-loglevel', 'error', '-y',
            '-ss', number_format(max(0.0, $seconds), 3, '.', ''),
            '-i', $inputPath,
            '-frames:v', '1',
            '-vf', 'scale=\'min(' . self::POSTER_MAX_WIDTH . ',iw)\':-2',
            '-q:v', '3',
            $outputPath,
        ];
    }

    /**
     * @param list<int> $heights
     */
    private static function scaleFilter(array $heights): string
    {
        $count = count($heights);
        if ($count === 1) {
            return '[0:v]scale=-2:' . $heights[0] . '[v0]';
        }

        $split = '[0:v]split=' . $count;
        $scales = [];
        foreach ($heights as $index => $height) {
            $split .= '[s' . $index . ']';
            $scales[] = '[s' . $index . ']scale=-2:' . $height . '[v' . $index . ']';
        }

        return $split . ';' . implode(';', $scales);
    }
}
