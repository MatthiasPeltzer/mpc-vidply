<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Dto;

/**
 * Installed ffmpeg/ffprobe binaries as seen by the version check.
 *
 * A null version with `$ffmpegAvailable` set means the binary runs but reports
 * no release number (a git or snapshot build).
 */
final readonly class FfmpegStatus
{
    public function __construct(
        public string $ffmpegPath,
        public string $ffprobePath,
        public bool $ffmpegAvailable,
        public bool $ffprobeAvailable,
        public ?string $ffmpegVersion,
        public ?string $ffprobeVersion,
        public string $minimumVersion,
        public string $error = '',
    ) {}

    public function isUsable(): bool
    {
        return $this->ffmpegAvailable && $this->ffprobeAvailable && !$this->isTooOld();
    }

    public function isTooOld(): bool
    {
        return $this->ffmpegVersion !== null
            && version_compare($this->ffmpegVersion, $this->minimumVersion, '<');
    }

    public function isVersionUnknown(): bool
    {
        return $this->ffmpegAvailable && $this->ffmpegVersion === null;
    }
}
