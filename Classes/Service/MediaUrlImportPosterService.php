<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Persists imported poster images to the tx_mpcvidply_media.poster FAL field.
 */
final class MediaUrlImportPosterService
{
    private const MEDIA_TABLE = 'tx_mpcvidply_media';

    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function hasPosterReference(int $mediaUid): bool
    {
        if ($mediaUid <= 0) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $count = (int)$queryBuilder
            ->count('uid')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($mediaUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter(self::MEDIA_TABLE)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter('poster')),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchOne();

        return $count > 0;
    }

    public function attachPosterFile(int $mediaUid, int $posterFileUid, int $pid): bool
    {
        if ($mediaUid <= 0 || $posterFileUid <= 0 || $pid <= 0) {
            return false;
        }

        if ($this->hasPosterReference($mediaUid)) {
            return false;
        }

        try {
            $this->resourceFactory->getFileObject($posterFileUid);
        } catch (FileDoesNotExistException) {
            return false;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'sys_file_reference' => [
                'NEW' . StringUtility::getUniqueId() => [
                    'uid_local' => $posterFileUid,
                    'uid_foreign' => $mediaUid,
                    'tablenames' => self::MEDIA_TABLE,
                    'fieldname' => 'poster',
                    'table_local' => 'sys_file',
                    'table_foreign' => self::MEDIA_TABLE,
                    'pid' => $pid,
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        return $this->hasPosterReference($mediaUid);
    }
}
