<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Dto;

final readonly class StreamConversionResult
{
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_REQUEUED = 'requeued';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public string $status,
        public int $recordUid,
        public string $message = '',
        public int $variantUid = 0,
    ) {}

    public static function converted(int $recordUid, int $variantUid): self
    {
        return new self(self::STATUS_CONVERTED, $recordUid, variantUid: $variantUid);
    }

    public static function requeued(int $recordUid, int $variantUid): self
    {
        return new self(self::STATUS_REQUEUED, $recordUid, 'The record changed during the conversion and was queued again.', $variantUid);
    }

    public static function skipped(int $recordUid, string $message): self
    {
        return new self(self::STATUS_SKIPPED, $recordUid, $message);
    }

    public static function failed(int $recordUid, string $message): self
    {
        return new self(self::STATUS_FAILED, $recordUid, $message);
    }
}
