<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Streaming;

use Mpc\MpcVidply\Dto\ProcessResult;

/**
 * Runs an external binary without a shell. Exists so tests can stand in for
 * ffmpeg and ffprobe.
 */
interface ProcessRunnerInterface
{
    /**
     * @param list<string> $command Binary followed by its arguments
     * @param float|null $timeout Seconds; null waits indefinitely
     */
    public function run(array $command, ?float $timeout = null, ?string $workingDirectory = null): ProcessResult;
}
