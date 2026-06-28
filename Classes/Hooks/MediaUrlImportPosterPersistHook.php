<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Hooks;

use Mpc\MpcVidply\Service\MediaUrlImportPosterService;
use Mpc\MpcVidply\Service\MediaUrlImportSessionService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Attaches imported poster images on save when FormEngine JS could not link them.
 */
final class MediaUrlImportPosterPersistHook
{
    private const MEDIA_TABLE = 'tx_mpcvidply_media';

    public function __construct(
        private readonly MediaUrlImportSessionService $sessionService,
        private readonly MediaUrlImportPosterService $posterService,
    ) {}

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        if (!isset($dataHandler->datamap[self::MEDIA_TABLE]) || !is_array($dataHandler->datamap[self::MEDIA_TABLE])) {
            return;
        }

        foreach (array_keys($dataHandler->datamap[self::MEDIA_TABLE]) as $id) {
            $mediaUid = $this->resolveMediaUid($id, $dataHandler);
            if ($mediaUid <= 0 || $this->posterService->hasPosterReference($mediaUid)) {
                continue;
            }

            $recordKey = $this->sessionService->buildRecordKey(self::MEDIA_TABLE, (string)$id);
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

    private function resolveMediaUid(string|int $id, DataHandler $dataHandler): int
    {
        if (is_string($id) && str_starts_with($id, 'NEW')) {
            return (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
        }

        return (int)$id;
    }
}
