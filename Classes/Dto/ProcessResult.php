<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Dto;

final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Last lines of stderr, where ffmpeg puts the reason it gave up.
     */
    public function errorTail(int $lines = 6): string
    {
        $all = preg_split('/\R/', trim($this->errorOutput)) ?: [];

        return trim(implode("\n", array_slice($all, -$lines)));
    }
}
