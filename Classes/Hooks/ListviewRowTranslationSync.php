<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Hooks;

use Mpc\MpcVidply\Service\ListviewRowLocalizationService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates and maintains localized `tx_mpcvidply_listview_row` records when a
 * listview content element or one of its translations is saved or localized.
 *
 * Without localized child rows, TYPO3's inline field on a translated CE keeps
 * showing the default-language headlines and the frontend cannot overlay them.
 */
final class ListviewRowTranslationSync
{
    private const CTYPE = 'mpc_vidply_listview';

    private readonly ListviewRowLocalizationService $localizationService;

    public function __construct(
        ?ListviewRowLocalizationService $localizationService = null
    ) {
        $this->localizationService = $localizationService
            ?? GeneralUtility::makeInstance(ListviewRowLocalizationService::class);
    }

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
            if ($row === [] || (int)($row['deleted'] ?? 0) > 0 || ($row['CType'] ?? '') !== self::CTYPE) {
                continue;
            }

            $l18nParent = (int)($row['l18n_parent'] ?? 0);
            if ($l18nParent > 0) {
                $this->localizationService->ensureLocalizedRowsForTranslation(
                    $l18nParent,
                    $uid,
                    (int)($row['sys_language_uid'] ?? 0)
                );
            } else {
                $this->localizationService->ensureLocalizedRowsForAllTranslations($uid);
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
            if ($row === [] || (int)($row['deleted'] ?? 0) > 0 || ($row['CType'] ?? '') !== self::CTYPE) {
                continue;
            }

            $this->localizationService->ensureLocalizedRowsForAllTranslations($sourceId);
        }
    }

    private function resolveTtContentUid(string|int $id, DataHandler $dataHandler): int
    {
        if (is_string($id) && str_starts_with($id, 'NEW')) {
            return (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
        }

        return (int)$id;
    }
}
