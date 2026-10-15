<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Hooks;

use Mpc\MpcVidply\Service\MediaPosterResolver;
use Mpc\MpcVidply\Service\MediaThumbnailRefresher;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Renews the file list thumbnail of a container file when the poster it shows
 * changes: a poster added, replaced or removed, a record pointed at another
 * media file, or a record deleted or restored.
 *
 * The poster each affected container file resolves to is noted before the
 * DataHandler run and compared afterwards, so saving a record without touching
 * its poster leaves the thumbnails alone.
 */
final class MediaPosterThumbnailHook
{
    use CollectsSavedRecordsTrait;

    private const MEDIA_TABLE = 'tx_mpcvidply_media';

    /**
     * DataHandler instance id => (container file uid => poster file uid).
     *
     * @var array<int, array<int, int>>
     */
    private array $postersBefore = [];

    public function __construct(
        private readonly MediaPosterResolver $posterResolver,
        private readonly MediaThumbnailRefresher $thumbnailRefresher,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    public function processDatamap_beforeStart(DataHandler $dataHandler): void
    {
        $recordUids = array_map(
            intval(...),
            array_filter(
                array_keys($dataHandler->datamap[self::MEDIA_TABLE] ?? []),
                static fn (int|string $id): bool => is_int($id) || ctype_digit($id),
            )
        );
        $this->rememberPosters($dataHandler, array_values($recordUids));
    }

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $this->refreshChangedThumbnails($dataHandler, array_keys($this->takeSavedRecords($dataHandler)));
    }

    /**
     * FormEngine removes a poster or media file with a separate delete command
     * for its file reference, processed after the datamap.
     */
    public function processCmdmap_preProcess(string $command, string $table, int|string $id, mixed $value, DataHandler $dataHandler): void
    {
        $recordUid = $this->resolveAffectedRecordUid($command, $table, (int)$id);
        if ($recordUid > 0) {
            $this->rememberPosters($dataHandler, [$recordUid]);
        }
    }

    public function processCmdmap_postProcess(string $command, string $table, int|string $id, mixed $value, DataHandler $dataHandler): void
    {
        $recordUid = $this->resolveAffectedRecordUid($command, $table, (int)$id);
        if ($recordUid > 0) {
            $this->refreshChangedThumbnails($dataHandler, [$recordUid]);
        }
    }

    protected function getCollectedTable(): string
    {
        return self::MEDIA_TABLE;
    }

    private function resolveAffectedRecordUid(string $command, string $table, int $id): int
    {
        if ($id <= 0 || !in_array($command, ['delete', 'undelete'], true)) {
            return 0;
        }
        if ($table === self::MEDIA_TABLE) {
            return $id;
        }
        if ($table !== 'sys_file_reference') {
            return 0;
        }

        $reference = BackendUtility::getRecord($table, $id, 'tablenames,fieldname,uid_foreign', '', false);
        if (!is_array($reference)
            || ($reference['tablenames'] ?? '') !== self::MEDIA_TABLE
            || !in_array($reference['fieldname'] ?? '', ['poster', 'media_file'], true)
        ) {
            return 0;
        }

        return (int)($reference['uid_foreign'] ?? 0);
    }

    /**
     * @param list<int> $recordUids
     */
    private function rememberPosters(DataHandler $dataHandler, array $recordUids): void
    {
        $key = spl_object_id($dataHandler);
        foreach ($this->posterResolver->findContainerFileUids($recordUids) as $fileUid) {
            $this->postersBefore[$key][$fileUid] ??= $this->posterResolver->findPosterFileUid($fileUid);
        }
    }

    /**
     * Container files the records did not use before the run (new record,
     * other media file) are always renewed: their thumbnail was built for
     * whichever record used them until now.
     *
     * @param list<int> $recordUids
     */
    private function refreshChangedThumbnails(DataHandler $dataHandler, array $recordUids): void
    {
        $key = spl_object_id($dataHandler);
        $postersBefore = $this->postersBefore[$key] ?? [];
        unset($this->postersBefore[$key]);

        $fileUids = array_unique([
            ...array_keys($postersBefore),
            ...$this->posterResolver->findContainerFileUids($recordUids),
        ]);

        foreach ($fileUids as $fileUid) {
            if (array_key_exists($fileUid, $postersBefore)
                && $postersBefore[$fileUid] === $this->posterResolver->findPosterFileUid($fileUid)
            ) {
                continue;
            }
            try {
                $file = $this->resourceFactory->getFileObject($fileUid);
            } catch (\Throwable) {
                continue;
            }
            $this->thumbnailRefresher->refresh($file);
        }
    }
}
