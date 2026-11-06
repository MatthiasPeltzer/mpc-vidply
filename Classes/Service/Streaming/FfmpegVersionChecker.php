<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Streaming;

use Mpc\MpcVidply\Dto\FfmpegStatus;

/**
 * Finds out whether the configured ffmpeg/ffprobe run and are recent enough
 * for fMP4 HLS, `-hls_master_name` on the DASH muxer and current x264.
 */
final class FfmpegVersionChecker
{
    public const MINIMUM_VERSION = '7.1';

    private ?FfmpegStatus $status = null;

    public function __construct(
        private readonly ProcessRunnerInterface $processRunner,
        private readonly StreamingSettings $settings,
    ) {}

    public function check(): FfmpegStatus
    {
        if ($this->status !== null) {
            return $this->status;
        }

        $configuration = $this->settings->get();
        $ffmpeg = $this->processRunner->run([$configuration->ffmpegPath, '-hide_banner', '-version'], 15.0);
        $ffprobe = $this->processRunner->run([$configuration->ffprobePath, '-hide_banner', '-version'], 15.0);

        $error = '';
        if (!$ffmpeg->isSuccessful()) {
            $error = $ffmpeg->errorTail(2);
        } elseif (!$ffprobe->isSuccessful()) {
            $error = $ffprobe->errorTail(2);
        }

        return $this->status = new FfmpegStatus(
            ffmpegPath: $configuration->ffmpegPath,
            ffprobePath: $configuration->ffprobePath,
            ffmpegAvailable: $ffmpeg->isSuccessful(),
            ffprobeAvailable: $ffprobe->isSuccessful(),
            ffmpegVersion: $ffmpeg->isSuccessful() ? self::parseVersion($ffmpeg->output) : null,
            ffprobeVersion: $ffprobe->isSuccessful() ? self::parseVersion($ffprobe->output) : null,
            minimumVersion: self::MINIMUM_VERSION,
            error: $error,
        );
    }

    /**
     * Release number from the first line of `-version`, e.g.
     * "ffmpeg version 8.0.1-static https://…" or "ffmpeg version n7.1.2-12-g…".
     * Git snapshots ("N-121000-g…") carry no release number and yield null.
     */
    public static function parseVersion(string $output): ?string
    {
        if (preg_match('/^\S+ version n?(\d+(?:\.\d+){0,2})/m', $output, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
