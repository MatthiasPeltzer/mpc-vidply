<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Hooks;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Dispatch skeleton for DataHandler hooks that keep the translations of a
 * content element in sync with their default-language source.
 *
 * Both entry points funnel into the same operation, so subclasses only
 * declare their CType and what "sync" means for them.
 *
 * {@see DataHandler::processDatamap_afterAllOperations()} is used rather than
 * `processDatamap_afterDatabaseOperations` because group/MM relations are only
 * written in {@see DataHandler::dbAnalysisStoreExec()}, after the latter hook
 * has run — syncing earlier would replicate the *previous* state. The latter
 * hook is still needed to learn which records were actually written:
 * `afterAllOperations` also sees records whose save the DataHandler denied.
 *
 * The subclasses write to the database directly, which is neither workspace
 * aware nor permission checked, so nothing is synced inside a workspace and
 * only translations in languages the editor may edit are touched.
 */
abstract class AbstractContentTranslationSyncHook
{
    use CollectsSavedRecordsTrait;

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $uids = array_keys($this->takeSavedRecords($dataHandler));
        if ($uids === [] || !$this->mayWriteLive($dataHandler)) {
            return;
        }

        foreach ($uids as $uid) {
            $row = BackendUtility::getRecord(
                'tt_content',
                $uid,
                'uid,CType,sys_language_uid,l18n_parent,deleted'
            ) ?? [];
            if ($row === [] || (int)($row['deleted'] ?? 0) > 0 || ($row['CType'] ?? '') !== $this->getContentType()) {
                continue;
            }

            $l18nParent = (int)($row['l18n_parent'] ?? 0);
            if ($l18nParent > 0) {
                $this->syncTranslationIfAllowed($dataHandler, $l18nParent, $uid, (int)($row['sys_language_uid'] ?? 0));
            } else {
                $this->syncAllTranslations($dataHandler, $uid);
            }
        }
    }

    public function processCmdmap_afterFinish(DataHandler $dataHandler): void
    {
        $ttContentCmds = $dataHandler->cmdmap['tt_content'] ?? null;
        if (!is_array($ttContentCmds) || !$this->mayWriteLive($dataHandler)) {
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

            $row = BackendUtility::getRecord('tt_content', $sourceId, 'CType,deleted') ?? [];
            if ($row === [] || (int)($row['deleted'] ?? 0) > 0 || ($row['CType'] ?? '') !== $this->getContentType()) {
                continue;
            }

            // The localized CE exists by now, so the source can be pushed onto it.
            $this->syncAllTranslations($dataHandler, $sourceId);
        }
    }

    /**
     * CType this hook is responsible for; every other content element is ignored.
     */
    abstract protected function getContentType(): string;

    protected function getCollectedTable(): string
    {
        return 'tt_content';
    }

    /**
     * Sync one known translation from its default-language source.
     */
    abstract protected function syncTranslation(int $sourceUid, int $translationUid, int $languageId): void;

    private function syncAllTranslations(DataHandler $dataHandler, int $sourceUid): void
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        // Hidden and scheduled translations are kept in sync as well.
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $translations = $qb
            ->select('uid', 'sys_language_uid')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('l18n_parent', $qb->createNamedParameter($sourceUid, Connection::PARAM_INT)),
                $qb->expr()->eq('CType', $qb->createNamedParameter($this->getContentType())),
                $qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($translations as $translation) {
            $this->syncTranslationIfAllowed(
                $dataHandler,
                $sourceUid,
                (int)($translation['uid'] ?? 0),
                (int)($translation['sys_language_uid'] ?? 0)
            );
        }
    }

    private function syncTranslationIfAllowed(DataHandler $dataHandler, int $sourceUid, int $translationUid, int $languageId): void
    {
        if ($translationUid <= 0 || $languageId <= 0) {
            return;
        }
        if (!$dataHandler->BE_USER->checkLanguageAccess($languageId)) {
            return;
        }

        $this->syncTranslation($sourceUid, $translationUid, $languageId);
        GeneralUtility::makeInstance(ReferenceIndex::class)->updateRefIndexTable('tt_content', $translationUid);
    }

    /**
     * The direct writes of the subclasses bypass workspace versioning, so they
     * must only run on live data.
     */
    private function mayWriteLive(DataHandler $dataHandler): bool
    {
        return (int)$dataHandler->BE_USER->workspace === 0;
    }
}
