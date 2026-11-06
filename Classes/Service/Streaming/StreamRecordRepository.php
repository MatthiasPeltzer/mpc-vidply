<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Streaming;

use Mpc\MpcVidply\Enums\StreamStatus;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Database access for the streaming conversion: media records, their file
 * references and translations. Reads ignore hidden/start/end restrictions on
 * purpose: a hidden record is still converted.
 */
final readonly class StreamRecordRepository
{
    public const MEDIA_TABLE = 'tx_mpcvidply_media';
    public const REFERENCE_TABLE = 'sys_file_reference';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function findRecord(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }
        $queryBuilder = $this->queryBuilder(self::MEDIA_TABLE);
        $row = $queryBuilder
            ->select('*')
            ->from(self::MEDIA_TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * Deleted rows included: the cmdmap hook reads a record after deleting it.
     *
     * @return array<string, mixed>|null
     */
    public function findRecordIncludingDeleted(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::MEDIA_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from(self::MEDIA_TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * Default-language records waiting for conversion, oldest request first.
     *
     * @return list<int>
     */
    public function findPendingUids(int $limit): array
    {
        $queryBuilder = $this->queryBuilder(self::MEDIA_TABLE);
        $rows = $queryBuilder
            ->select('uid')
            ->from(self::MEDIA_TABLE)
            ->where(
                $queryBuilder->expr()->eq('stream_convert', 1),
                $queryBuilder->expr()->eq('stream_status', $queryBuilder->createNamedParameter(StreamStatus::Pending->value)),
                $queryBuilder->expr()->eq('media_type', $queryBuilder->createNamedParameter('video')),
                $queryBuilder->expr()->eq('sys_language_uid', 0),
                $queryBuilder->expr()->eq('stream_source', 0),
            )
            ->orderBy('tstamp', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map(intval(...), $rows));
    }

    /**
     * Records left in "processing" by a run that died (out of memory,
     * container restart) for longer than `$seconds`.
     *
     * @return list<int>
     */
    public function findStaleProcessingUids(int $seconds): array
    {
        $queryBuilder = $this->queryBuilder(self::MEDIA_TABLE);
        $rows = $queryBuilder
            ->select('uid')
            ->from(self::MEDIA_TABLE)
            ->where(
                $queryBuilder->expr()->eq('stream_status', $queryBuilder->createNamedParameter(StreamStatus::Processing->value)),
                $queryBuilder->expr()->lt('tstamp', $queryBuilder->createNamedParameter(time() - $seconds, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map(intval(...), $rows));
    }

    /**
     * Write queue fields without DataHandler: no history entry, no hooks.
     *
     * @param array<string, int|string|null> $fields
     */
    public function updateRecord(int $uid, array $fields, bool $touch = true): void
    {
        if ($touch) {
            $fields['tstamp'] = time();
        }
        $this->connectionPool->getConnectionForTable(self::MEDIA_TABLE)->update(self::MEDIA_TABLE, $fields, ['uid' => $uid]);
    }

    /**
     * Translations of a default-language record, keyed by language uid.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findTranslations(int $defaultUid): array
    {
        $queryBuilder = $this->queryBuilder(self::MEDIA_TABLE);
        $rows = $queryBuilder
            ->select('*')
            ->from(self::MEDIA_TABLE)
            ->where(
                $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($defaultUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('sys_language_uid', 0),
            )
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $byLanguage = [];
        foreach ($rows as $row) {
            $byLanguage[(int)$row['sys_language_uid']] ??= $row;
        }

        return $byLanguage;
    }

    /**
     * File references of one field with the columns of their sys_file
     * (prefixed `file_`), in editor order.
     *
     * @return list<array<string, mixed>>
     */
    public function findReferences(string $tableName, int $foreignUid, string $fieldName): array
    {
        $queryBuilder = $this->queryBuilder(self::REFERENCE_TABLE);
        $rows = $queryBuilder
            ->select('ref.*', 'file.identifier AS file_identifier', 'file.name AS file_name', 'file.extension AS file_extension', 'file.sha1 AS file_sha1', 'file.storage AS file_storage', 'file.missing AS file_missing')
            ->from(self::REFERENCE_TABLE, 'ref')
            ->join('ref', 'sys_file', 'file', $queryBuilder->expr()->eq('file.uid', $queryBuilder->quoteIdentifier('ref.uid_local')))
            ->where(
                $queryBuilder->expr()->eq('ref.tablenames', $queryBuilder->createNamedParameter($tableName)),
                $queryBuilder->expr()->eq('ref.uid_foreign', $queryBuilder->createNamedParameter($foreignUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('ref.fieldname', $queryBuilder->createNamedParameter($fieldName)),
            )
            ->orderBy('ref.sorting_foreign', 'ASC')
            ->addOrderBy('ref.uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values($rows);
    }

    /**
     * Soft-delete every reference of one field, as DataHandler would.
     */
    public function deleteReferences(string $tableName, int $foreignUid, string $fieldName): void
    {
        $this->connectionPool->getConnectionForTable(self::REFERENCE_TABLE)->update(
            self::REFERENCE_TABLE,
            ['deleted' => 1, 'tstamp' => time()],
            ['tablenames' => $tableName, 'uid_foreign' => $foreignUid, 'fieldname' => $fieldName, 'deleted' => 0]
        );
    }

    /**
     * @param array<string, mixed> $row Without uid
     */
    public function insertReference(array $row): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::REFERENCE_TABLE);
        $connection->insert(self::REFERENCE_TABLE, $row);

        return (int)$connection->lastInsertId();
    }

    /**
     * Columns of sys_file_reference, so copied rows never carry join columns.
     *
     * @return list<string>
     */
    public function referenceColumns(): array
    {
        static $columns = null;
        if ($columns === null) {
            $schemaManager = $this->connectionPool->getConnectionForTable(self::REFERENCE_TABLE)->createSchemaManager();
            $columns = array_values(array_map(
                static fn ($column): string => $column->getName(),
                $schemaManager->listTableColumns(self::REFERENCE_TABLE)
            ));
        }

        return $columns;
    }

    private function queryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder;
    }
}
