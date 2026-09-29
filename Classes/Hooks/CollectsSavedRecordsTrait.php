<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Hooks;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Remembers which records of one table a DataHandler run actually wrote.
 *
 * `processDatamap_afterAllOperations` receives the full submitted datamap,
 * including records whose save was denied (missing page or language access,
 * read-only fields, …). `processDatamap_afterDatabaseOperations` only runs for
 * records that were written, so hooks collect their uids there and act on them
 * once all relations are stored.
 *
 * Hooks are shared services, so the uids are kept per DataHandler instance to
 * keep nested DataHandler runs apart.
 */
trait CollectsSavedRecordsTrait
{
    /**
     * DataHandler instance id => (record uid => id as submitted, e.g. `NEW…`).
     *
     * @var array<int, array<int, string>>
     */
    private array $savedRecords = [];

    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array $fieldArray,
        DataHandler $dataHandler
    ): void {
        if ($table !== $this->getCollectedTable()) {
            return;
        }
        $uid = is_string($id) && str_starts_with($id, 'NEW')
            ? (int)($dataHandler->substNEWwithIDs[$id] ?? 0)
            : (int)$id;
        if ($uid > 0) {
            $this->savedRecords[spl_object_id($dataHandler)][$uid] = (string)$id;
        }
    }

    abstract protected function getCollectedTable(): string;

    /**
     * Records written by this DataHandler run, as uid => id in the submitted
     * datamap (`NEW…` for inserts). The list is cleared on read.
     *
     * @return array<int, string>
     */
    private function takeSavedRecords(DataHandler $dataHandler): array
    {
        $key = spl_object_id($dataHandler);
        $records = $this->savedRecords[$key] ?? [];
        unset($this->savedRecords[$key]);

        return $records;
    }
}
