<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Dto;

/**
 * What ffprobe reported about a source video.
 */
final readonly class MediaProbeResult
{
    public function __construct(
        public int $width,
        public int $height,
        public float $duration,
        public bool $hasAudio,
    ) {}
}
