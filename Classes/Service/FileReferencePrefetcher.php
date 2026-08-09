<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Batch-loads `sys_file_reference` rows for a set of media records.
 *
 * Resolving references one media record at a time turns every playlist, listview
 * and backend preview into an N+1 query, so all callers share this single lookup.
 */
final class FileReferencePrefetcher
{
    private const TABLE = 'sys_file_reference';

    private const FOREIGN_TABLE = 'tx_mpcvidply_media';

    private readonly ConnectionPool $connectionPool;
    private readonly ResourceFactory $resourceFactory;

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?ResourceFactory $resourceFactory = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->resourceFactory = $resourceFactory ?? GeneralUtility::makeInstance(ResourceFactory::class);
    }

    /**
     * @param list<int> $mediaUids
     * @param list<string> $fieldNames
     * @param bool $includeEmptyDefaults Seed every requested uid/field with an empty
     *                                   list, so callers can tell "prefetched, none
     *                                   found" apart from "never prefetched".
     * @return array<int, array<string, list<FileReference>>>
     */
    public function prefetch(array $mediaUids, array $fieldNames, bool $includeEmptyDefaults = false): array
    {
        $result = [];
        if ($mediaUids === [] || $fieldNames === []) {
            return $result;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $rows = $queryBuilder
            ->select('uid', 'uid_foreign', 'fieldname')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'tablenames',
                    $queryBuilder->createNamedParameter(self::FOREIGN_TABLE)
                ),
                $queryBuilder->expr()->in(
                    'fieldname',
                    $queryBuilder->createNamedParameter($fieldNames, Connection::PARAM_STR_ARRAY)
                ),
                $queryBuilder->expr()->in(
                    'uid_foreign',
                    $queryBuilder->createNamedParameter($mediaUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->orderBy('uid_foreign', 'ASC')
            ->addOrderBy('fieldname', 'ASC')
            ->addOrderBy('sorting_foreign', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $uid = (int)($row['uid'] ?? 0);
            $uidForeign = (int)($row['uid_foreign'] ?? 0);
            $fieldName = (string)($row['fieldname'] ?? '');
            if ($uid <= 0 || $uidForeign <= 0 || $fieldName === '') {
                continue;
            }

            try {
                $fileReference = $this->resourceFactory->getFileReferenceObject($uid);
            } catch (ResourceDoesNotExistException) {
                continue;
            }

            $result[$uidForeign][$fieldName] ??= [];
            $result[$uidForeign][$fieldName][] = $fileReference;
        }

        if ($includeEmptyDefaults) {
            foreach ($mediaUids as $mediaUid) {
                $result[$mediaUid] ??= [];
                foreach ($fieldNames as $fieldName) {
                    $result[$mediaUid][$fieldName] ??= [];
                }
            }
        }

        return $result;
    }

    /**
     * @param list<int> $mediaUids
     * @return array<int, list<FileReference>> Only media records that actually have references
     */
    public function prefetchField(array $mediaUids, string $fieldName): array
    {
        $result = [];
        foreach ($this->prefetch($mediaUids, [$fieldName]) as $mediaUid => $byField) {
            $references = $byField[$fieldName] ?? [];
            if ($references !== []) {
                $result[$mediaUid] = $references;
            }
        }

        return $result;
    }
}
