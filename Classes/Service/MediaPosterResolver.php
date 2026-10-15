<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Finds the poster an editor attached to a VidPly media record for a given
 * media file. The poster belongs to the record, not to the file, so one file
 * used by several records can have several posters: default-language records
 * win, then the oldest record, then the first poster in its list.
 */
final readonly class MediaPosterResolver
{
    /**
     * Container files of the VidPly online media helpers. YouTube and Vimeo
     * are left out: core downloads a thumbnail for them.
     */
    public const CONTAINER_EXTENSIONS = ['externalvideo', 'externalaudio', 'soundcloud', 'hls', 'dash'];

    private const MEDIA_TABLE = 'tx_mpcvidply_media';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function findPosterFileUid(int $mediaFileUid): int
    {
        if ($mediaFileUid <= 0) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $posterUid = $queryBuilder
            ->select('poster.uid_local')
            ->from('sys_file_reference', 'media')
            ->join(
                'media',
                self::MEDIA_TABLE,
                'record',
                $queryBuilder->expr()->eq('record.uid', $queryBuilder->quoteIdentifier('media.uid_foreign'))
            )
            ->join(
                'record',
                'sys_file_reference',
                'poster',
                (string)$queryBuilder->expr()->and(
                    $queryBuilder->expr()->eq('poster.uid_foreign', $queryBuilder->quoteIdentifier('record.uid')),
                    $queryBuilder->expr()->eq('poster.tablenames', $queryBuilder->createNamedParameter(self::MEDIA_TABLE)),
                    $queryBuilder->expr()->eq('poster.fieldname', $queryBuilder->createNamedParameter('poster')),
                )
            )
            ->where(
                $queryBuilder->expr()->eq('media.uid_local', $queryBuilder->createNamedParameter($mediaFileUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('media.tablenames', $queryBuilder->createNamedParameter(self::MEDIA_TABLE)),
                $queryBuilder->expr()->eq('media.fieldname', $queryBuilder->createNamedParameter('media_file')),
            )
            ->orderBy('record.sys_language_uid')
            ->addOrderBy('record.uid')
            ->addOrderBy('poster.sorting_foreign')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return (int)$posterUid;
    }

    /**
     * Container files (see {@see CONTAINER_EXTENSIONS}) the given media records
     * use as their media file. Deleted records still count, so a record that is
     * just being deleted or restored is covered.
     *
     * @param list<int> $mediaRecordUids
     * @return list<int>
     */
    public function findContainerFileUids(array $mediaRecordUids): array
    {
        $mediaRecordUids = array_values(array_filter($mediaRecordUids, static fn (int $uid): bool => $uid > 0));
        if ($mediaRecordUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        $fileUids = $queryBuilder
            ->select('media.uid_local')
            ->distinct()
            ->from('sys_file_reference', 'media')
            ->join(
                'media',
                'sys_file',
                'file',
                $queryBuilder->expr()->eq('file.uid', $queryBuilder->quoteIdentifier('media.uid_local'))
            )
            ->where(
                $queryBuilder->expr()->in('media.uid_foreign', $queryBuilder->createNamedParameter($mediaRecordUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('media.tablenames', $queryBuilder->createNamedParameter(self::MEDIA_TABLE)),
                $queryBuilder->expr()->eq('media.fieldname', $queryBuilder->createNamedParameter('media_file')),
                $queryBuilder->expr()->in('file.extension', $queryBuilder->createNamedParameter(self::CONTAINER_EXTENSIONS, Connection::PARAM_STR_ARRAY)),
            )
            ->orderBy('media.uid_local')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(intval(...), $fileUids);
    }
}
