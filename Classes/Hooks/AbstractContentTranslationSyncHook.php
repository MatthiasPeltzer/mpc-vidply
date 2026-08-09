<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Hooks;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Dispatch skeleton for DataHandler hooks that keep the translations of a
 * content element in sync with their default-language source.
 *
 * Both entry points funnel into the same two operations, so subclasses only
 * declare their CType and what "sync" means for them.
 *
 * {@see DataHandler::processDatamap_afterAllOperations()} is used rather than
 * `processDatamap_afterDatabaseOperations` because group/MM relations are only
 * written in {@see DataHandler::dbAnalysisStoreExec()}, after the latter hook
 * has run — syncing earlier would replicate the *previous* state.
 */
abstract class AbstractContentTranslationSyncHook
{
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
                'uid,CType,sys_language_uid,l18n_parent,deleted'
            ) ?? [];
            if ($row === [] || (int)($row['deleted'] ?? 0) > 0 || ($row['CType'] ?? '') !== $this->getContentType()) {
                continue;
            }

            $l18nParent = (int)($row['l18n_parent'] ?? 0);
            if ($l18nParent > 0) {
                $this->syncTranslation($l18nParent, $uid, (int)($row['sys_language_uid'] ?? 0));
            } else {
                $this->syncAllTranslations($uid);
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

            $row = BackendUtility::getRecord('tt_content', $sourceId, 'CType,deleted') ?? [];
            if ($row === [] || (int)($row['deleted'] ?? 0) > 0 || ($row['CType'] ?? '') !== $this->getContentType()) {
                continue;
            }

            // The localized CE exists by now, so the source can be pushed onto it.
            $this->syncAllTranslations($sourceId);
        }
    }

    /**
     * CType this hook is responsible for; every other content element is ignored.
     */
    abstract protected function getContentType(): string;

    /**
     * Sync one known translation from its default-language source.
     */
    abstract protected function syncTranslation(int $sourceUid, int $translationUid, int $languageId): void;

    /**
     * Sync every translation of a default-language source.
     */
    abstract protected function syncAllTranslations(int $sourceUid): void;

    private function resolveTtContentUid(string|int $id, DataHandler $dataHandler): int
    {
        if (is_string($id) && str_starts_with($id, 'NEW')) {
            return (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
        }

        return (int)$id;
    }
}
