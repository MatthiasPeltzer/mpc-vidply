<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Dto;

final readonly class SrtConversionResult
{
    public function __construct(
        public bool $success,
        public string $vtt = '',
        public string $errorMessage = '',
    ) {}

    public static function ok(string $vtt): self
    {
        return new self(true, $vtt);
    }

    public static function fail(string $errorMessage): self
    {
        return new self(false, errorMessage: $errorMessage);
    }
}
