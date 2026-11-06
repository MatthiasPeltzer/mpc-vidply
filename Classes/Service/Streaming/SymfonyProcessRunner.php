<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Streaming;

use Mpc\MpcVidply\Dto\ProcessResult;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunnerInterface
{
    public function run(array $command, ?float $timeout = null, ?string $workingDirectory = null): ProcessResult
    {
        $process = new Process($command, $workingDirectory, null, null, $timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            return new ProcessResult(124, $process->getOutput(), $process->getErrorOutput() . "\n" . $exception->getMessage());
        } catch (ProcessStartFailedException|RuntimeException $exception) {
            return new ProcessResult(127, '', $exception->getMessage());
        }

        return new ProcessResult($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput());
    }
}
