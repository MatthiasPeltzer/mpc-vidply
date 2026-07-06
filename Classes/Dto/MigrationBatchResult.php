<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Dto;

final readonly class MigrationBatchResult
{
    /**
     * @param FileConversionResult[] $results
     */
    public function __construct(
        public array $results,
    ) {}

    public function countByStatus(string $status): int
    {
        return count(array_filter(
            $this->results,
            static fn (FileConversionResult $result): bool => $result->status === $status
        ));
    }

    public function convertedCount(): int
    {
        return $this->countByStatus(FileConversionResult::STATUS_CONVERTED);
    }

    public function skippedCount(): int
    {
        return $this->countByStatus(FileConversionResult::STATUS_SKIPPED);
    }

    public function failedCount(): int
    {
        return $this->countByStatus(FileConversionResult::STATUS_FAILED);
    }
}
