<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Hooks;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
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
 * When a **new** translation is created, only the translated `tt_content` is in the
 * request (or only the source is in a `localize` cmd) — the other hook branch runs
 * {@see self::replicateFromParentToTranslationUids} from the default-language
 * source so MM rows are created for the new CE. Saving the source alone is not
 * always enough, so the base class also runs after `localize` / `copyToLanguage`
 * and when a translation record is saved.
 */
final class VidPlyPlaylistTranslationSync extends AbstractContentTranslationSyncHook
{
    private const CTYPE = 'mpc_vidply';
    private const MM_TABLE = 'tx_mpcvidply_content_media_mm';

    protected function getContentType(): string
    {
        return self::CTYPE;
    }

    protected function syncTranslation(int $sourceUid, int $translationUid, int $languageId): void
    {
        $this->replicateFromParentToTranslationUids($sourceUid, [$translationUid], self::CTYPE);
    }

    protected function syncAllTranslations(int $sourceUid): void
    {
        $this->replicateSourcePlaylistToTranslations($sourceUid);
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
            array_map(static fn (mixed $v): int => (int)$v, $translatedUids),
            self::CTYPE
        );
    }

    /**
     * Copy playlist MM rows from the default-language `tt_content` to one or more
     * target `tt_content` uids (local sides). Used for translation saves and localize.
     *
     * @param list<int> $translationUids
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
            array_map(static fn (mixed $v): int => (int)$v, $translationUids),
            static fn (int $u): bool => $u > 0
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
