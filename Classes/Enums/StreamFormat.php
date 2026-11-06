<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Enums;

/**
 * Adaptive-streaming output an editor picks for a video record.
 *
 * Corresponds to the `stream_format` column in tx_mpcvidply_media. "Both"
 * writes one set of fMP4 (CMAF) segments that a DASH and an HLS manifest share.
 */
enum StreamFormat: string
{
    case Hls = 'hls';
    case Dash = 'dash';
    case Both = 'both';

    public static function fromRecordValue(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? $value : '') ?? self::Hls;
    }

    public function producesHls(): bool
    {
        return $this !== self::Dash;
    }

    public function producesDash(): bool
    {
        return $this !== self::Hls;
    }
}
