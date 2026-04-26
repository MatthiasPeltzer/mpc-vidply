<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Hooks;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Keeps `tx_mpcvidply_content_media_mm` in sync for translated `mpc_vidply` content
 * elements when the default-language (source) record is saved.
 *
 * The field `tt_content.tx_mpcvidply_media_items` is `l10n_mode=exclude`, but legacy or
 * copied rows can still leave MM entries on `uid_local` = the translation. Those are
 * what editors see in the English form (e.g. 12 items) while the German record has
 * 34. After every save of the source record, this hook replaces translation MM
 * with a copy of the default playlist.
 *
 * Implementation uses {@see DataHandler::processDatamap_afterAllOperations()} — not
 * `processDatamap_afterDatabaseOperations` — because group/MM relations are written
 * in {@see DataHandler::dbAnalysisStoreExec()} *after* the latter hook runs. Running
 * too early would copy the *previous* playlist from the database.
 *
 * When a **new** translation is created, only the translated `tt_content` is in the
 * request (or only the source is in a `localize` cmd) — the other hook branch runs
 * {@see self::replicateFromParentToTranslationUids} from the default-language
 * source so MM rows are created for the new CE. Saving the source alone is not
 * always enough, so we also run after `localize` / `copyToLanguage` and when a
 * translation record is saved.
 */
final class VidPlyPlaylistTranslationSync
{
    private const CTYPE = 'mpc_vidply';
    private const MM_TABLE = 'tx_mpcvidply_content_media_mm';

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        if (!isset($dataHandler->datamap['tt_content']) || !is_array($dataHandler->datamap['tt_content'])) {
            return;
        }

        foreach (array_keys($dataHandler->datamap['tt_content']) as $id) {
            $uid = $this->resolveTtContentUid($id, $dataHandler);
            if ($uid <= 0) {
                continue;
            }

            $row = BackendUtility::getRecord(
                'tt_content',
                $uid,
                'uid,CType,sys_language_uid,l18n_parent',
            ) ?? [];
            if ($row === []) {
                continue;
            }

            if (($row['CType'] ?? '') !== self::CTYPE) {
                continue;
            }

            $l18nParent = (int)($row['l18n_parent'] ?? 0);
            if ($l18nParent > 0) {
                $this->replicateFromParentToTranslationUids(
                    $l18nParent,
                    [$uid],
                    self::CTYPE
                );
            } else {
                $this->replicateSourcePlaylistToTranslations($uid);
            }
        }
    }

    public function processCmdmap_afterFinish(DataHandler $dataHandler): void
    {
        $ttContentCmds = $dataHandler->cmdmap['tt_content'] ?? null;
        if (!is_array($ttContentCmds)) {
            return;
        }

        foreach ($ttContentCmds as $sourceId => $commands) {
            if (!is_array($commands)) {
                continue;
            }
            if (!isset($commands['localize']) && !isset($commands['copyToLanguage'])) {
                continue;
            }
            $sourceId = (int)$sourceId;
            if ($sourceId <= 0) {
                continue;
            }
            $row = BackendUtility::getRecord('tt_content', $sourceId, 'CType') ?? [];
            if (($row['CType'] ?? '') !== self::CTYPE) {
                continue;
            }
            // New localized CE exists now: push the source playlist to all its translations
            $this->replicateSourcePlaylistToTranslations($sourceId);
        }
    }

    private function resolveTtContentUid(string|int $id, DataHandler $dataHandler): int
    {
        if (is_string($id) && str_starts_with($id, 'NEW')) {
            $mapped = (int)($dataHandler->substNEWwithIDs[$id] ?? 0);

            return $mapped;
        }

        return (int)$id;
    }

    private function replicateSourcePlaylistToTranslations(int $sourceContentUid): void
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $qb = $connectionPool->getQueryBuilderForTable('tt_content');
        $translatedUids = $qb
            ->select('uid')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('l18n_parent', $qb->createNamedParameter($sourceContentUid, Connection::PARAM_INT)),
                $qb->expr()->eq('CType', $qb->createNamedParameter(self::CTYPE)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchFirstColumn();
        if ($translatedUids === []) {
            return;
        }

        $this->replicateFromParentToTranslationUids(
            $sourceContentUid,
            array_map(static fn(mixed $v): int => (int)$v, $translatedUids),
            self::CTYPE
        );
    }

    /**
     * Copy playlist MM rows from the default-language `tt_content` to one or more
     * target `tt_content` uids (local sides). Used for translation saves and localize.
     */
    private function replicateFromParentToTranslationUids(
        int $sourceContentUid,
        array $translationUids,
        string $assertSourceCType = self::CTYPE
    ): void {
        if ($sourceContentUid <= 0) {
            return;
        }
        $translationUids = array_values(array_filter(
            array_map(static fn(mixed $v): int => (int)$v, $translationUids),
            static fn(int $u): bool => $u > 0
        ));
        if ($translationUids === []) {
            return;
        }

        $source = BackendUtility::getRecord('tt_content', $sourceContentUid, 'CType,deleted') ?? [];
        if (($source['deleted'] ?? 0) > 0) {
            return;
        }
        if ($assertSourceCType !== '' && ($source['CType'] ?? '') !== $assertSourceCType) {
            return;
        }

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $mm = $connectionPool->getQueryBuilderForTable(self::MM_TABLE);
        $relations = $mm
            ->select('uid_foreign', 'sorting', 'sorting_foreign')
            ->from(self::MM_TABLE)
            ->where(
                $mm->expr()->eq('uid_local', $mm->createNamedParameter($sourceContentUid, Connection::PARAM_INT))
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
        if ($relations === []) {
            $this->deleteMmForLocals($connectionPool, $translationUids);

            return;
        }

        $connection = $connectionPool->getConnectionForTable(self::MM_TABLE);
        $connection->beginTransaction();
        try {
            $this->deleteMmForLocals($connectionPool, $translationUids);
            foreach ($translationUids as $localUid) {
                foreach ($relations as $rel) {
                    $foreign = (int)($rel['uid_foreign'] ?? 0);
                    if ($foreign <= 0) {
                        continue;
                    }
                    $connection->insert(
                        self::MM_TABLE,
                        [
                            'uid_local' => $localUid,
                            'uid_foreign' => $foreign,
                            'sorting' => (int)($rel['sorting'] ?? 0),
                            'sorting_foreign' => (int)($rel['sorting_foreign'] ?? 0),
                        ]
                    );
                }
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * @param list<int> $localUids
     */
    private function deleteMmForLocals(ConnectionPool $connectionPool, array $localUids): void
    {
        if ($localUids === []) {
            return;
        }
        $qb = $connectionPool->getQueryBuilderForTable(self::MM_TABLE);
        $qb->delete(self::MM_TABLE)
            ->where(
                $qb->expr()->in('uid_local', $qb->createNamedParameter($localUids, Connection::PARAM_INT_ARRAY))
            )
            ->executeStatement();
    }
}
