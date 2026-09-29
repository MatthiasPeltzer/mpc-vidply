<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Hooks;

use Mpc\MpcVidply\Service\MediaUrlImportPosterService;
use Mpc\MpcVidply\Service\MediaUrlImportSessionService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Attaches imported poster images on save when FormEngine JS could not link them.
 *
 * Only records the DataHandler actually wrote are considered, and only for
 * editors who may edit the poster field: the nested DataHandler that creates
 * the file reference checks the `sys_file_reference` insert alone, not the
 * exclude field on the media record.
 */
final class MediaUrlImportPosterPersistHook
{
    use CollectsSavedRecordsTrait;

    private const MEDIA_TABLE = 'tx_mpcvidply_media';
    private const POSTER_FIELD = 'poster';

    public function __construct(
        private readonly MediaUrlImportSessionService $sessionService,
        private readonly MediaUrlImportPosterService $posterService,
    ) {}

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $savedRecords = $this->takeSavedRecords($dataHandler);
        if ($savedRecords === [] || !$this->mayEditPosterField($dataHandler)) {
            return;
        }

        foreach ($savedRecords as $mediaUid => $submittedId) {
            if ($this->posterService->hasPosterReference($mediaUid)) {
                continue;
            }

            $recordKey = $this->sessionService->buildRecordKey(self::MEDIA_TABLE, $submittedId);
            $posterFileUid = $this->sessionService->resolvePosterFileUidForSave($recordKey);
            if ($posterFileUid <= 0) {
                continue;
            }

            $record = BackendUtility::getRecord(self::MEDIA_TABLE, $mediaUid);
            $pid = is_array($record) ? (int)($record['pid'] ?? 0) : 0;
            if ($pid <= 0) {
                continue;
            }

            $this->posterService->attachPosterFile($mediaUid, $posterFileUid, $pid);
        }
    }

    protected function getCollectedTable(): string
    {
        return self::MEDIA_TABLE;
    }

    private function mayEditPosterField(DataHandler $dataHandler): bool
    {
        $backendUser = $dataHandler->BE_USER;
        if ($backendUser->isAdmin()) {
            return true;
        }
        if (empty($GLOBALS['TCA'][self::MEDIA_TABLE]['columns'][self::POSTER_FIELD]['exclude'])) {
            return true;
        }

        return $backendUser->check('non_exclude_fields', self::MEDIA_TABLE . ':' . self::POSTER_FIELD);
    }
}
