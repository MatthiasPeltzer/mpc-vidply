<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Hooks;

use Mpc\MpcVidply\Dto\FileConversionResult;
use Mpc\MpcVidply\Service\SrtCaptionMigrationService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

final class SrtCaptionConversionHook
{
    private const MEDIA_TABLE = 'tx_mpcvidply_media';

    public function __construct(
        private readonly SrtCaptionMigrationService $migrationService,
        private readonly FlashMessageService $flashMessageService,
    ) {}

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        if (!isset($dataHandler->datamap[self::MEDIA_TABLE]) || !is_array($dataHandler->datamap[self::MEDIA_TABLE])) {
            return;
        }

        if (!$this->isBackendRequest()) {
            return;
        }

        $mediaUids = [];
        foreach (array_keys($dataHandler->datamap[self::MEDIA_TABLE]) as $id) {
            $uid = $this->resolveMediaUid($id, $dataHandler);
            if ($uid > 0) {
                $mediaUids[] = $uid;
            }
        }

        if ($mediaUids === []) {
            return;
        }

        $batchResult = $this->migrationService->convertForMediaRecords($mediaUids);
        $this->enqueueFlashMessages($batchResult->results);
    }

    private function resolveMediaUid(string|int $id, DataHandler $dataHandler): int
    {
        if (is_string($id) && str_starts_with($id, 'NEW')) {
            return (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
        }

        return (int)$id;
    }

    private function isBackendRequest(): bool
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return false;
        }

        return ApplicationType::fromRequest($request)->isBackend();
    }

    /**
     * @param FileConversionResult[] $results
     */
    private function enqueueFlashMessages(array $results): void
    {
        if ($results === []) {
            return;
        }

        $queue = $this->flashMessageService->getMessageQueueByIdentifier(FlashMessageQueue::NOTIFICATION_QUEUE);

        foreach ($results as $result) {
            if ($result->status === FileConversionResult::STATUS_CONVERTED) {
                $queue->enqueue(new FlashMessage(
                    sprintf(
                        'Caption file "%s" was converted to WebVTT ("%s") and is ready for playback.',
                        $result->originalFileName,
                        $result->newFileName
                    ),
                    'Subtitle converted to WebVTT',
                    ContextualFeedbackSeverity::INFO,
                    true
                ));
                continue;
            }

            if ($result->status === FileConversionResult::STATUS_FAILED) {
                $queue->enqueue(new FlashMessage(
                    sprintf(
                        'Could not convert subtitle file "%s": %s',
                        $result->originalFileName,
                        $result->message
                    ),
                    'Subtitle conversion failed',
                    ContextualFeedbackSeverity::ERROR,
                    true
                ));
            }
        }
    }
}
