<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Dto;

final readonly class FileConversionResult
{
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public string $status,
        public string $originalFileName,
        public string $message = '',
        public string $newFileName = '',
    ) {}

    public static function converted(string $originalFileName, string $newFileName): self
    {
        return new self(self::STATUS_CONVERTED, $originalFileName, newFileName: $newFileName);
    }

    public static function skipped(string $originalFileName, string $message): self
    {
        return new self(self::STATUS_SKIPPED, $originalFileName, $message);
    }

    public static function failed(string $originalFileName, string $message): self
    {
        return new self(self::STATUS_FAILED, $originalFileName, $message);
    }
}
