<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Enums;

/**
 * Conversion state of a video record, stored in `stream_status`.
 *
 * The empty string is "never queued", so existing records need no migration.
 */
enum StreamStatus: string
{
    case None = '';
    case Pending = 'pending';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';

    public static function fromRecordValue(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? $value : '') ?? self::None;
    }
}
