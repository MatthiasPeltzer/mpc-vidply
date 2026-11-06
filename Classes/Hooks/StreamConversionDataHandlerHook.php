<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Hooks;

use Mpc\MpcVidply\Enums\StreamFormat;
use Mpc\MpcVidply\Enums\StreamStatus;
use Mpc\MpcVidply\Service\Streaming\StreamingSettings;
use Mpc\MpcVidply\Service\Streaming\StreamOutputStorage;
use Mpc\MpcVidply\Service\Streaming\StreamRecordRepository;
use Mpc\MpcVidply\Service\Streaming\StreamSourceCollector;
use Mpc\MpcVidply\Service\Streaming\StreamVariantRecordService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Keeps the streaming queue in step with what editors save.
 *
 * Saving a video with "Convert to stream" on queues it (status "pending")
 * when it was never converted, failed, or its files, format or the rendition
 * settings changed since the last run. Turning the option off, or deleting
 * the original, removes the variant record and its output folder. Deleting
 * the variant turns the option off on the original; copies start unconverted.
 *
 * The conversion itself runs in `mpc-vidply:stream:convert`.
 */
final class StreamConversionDataHandlerHook
{
    use CollectsSavedRecordsTrait;

    private const MEDIA_TABLE = StreamRecordRepository::MEDIA_TABLE;
    private const LABELS = 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:';

    public function __construct(
        private readonly StreamingSettings $settings,
        private readonly StreamRecordRepository $repository,
        private readonly StreamSourceCollector $sourceCollector,
        private readonly StreamVariantRecordService $variantService,
        private readonly StreamOutputStorage $outputStorage,
        private readonly FlashMessageService $flashMessageService,
    ) {}

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $recordUids = array_keys($this->takeSavedRecords($dataHandler));
        // Nested runs write copies and localizations, not editor saves;
        // copies are reset in processCmdmap_postProcess().
        if ($recordUids === [] || !$dataHandler->isOuterMostInstance() || !$this->settings->isEnabled()) {
            return;
        }

        foreach ($recordUids as $recordUid) {
            $record = $this->repository->findRecord($recordUid);
            if ($record === null
                || (int)$record['sys_language_uid'] !== 0
                || (int)($record['stream_source'] ?? 0) > 0
                || ($record['media_type'] ?? '') !== 'video'
            ) {
                continue;
            }

            if ((int)($record['stream_convert'] ?? 0) === 1) {
                $this->queue($record);
            } elseif ($this->hasStreamingState($record)) {
                $this->removeVariant($record);
                $this->addFlashMessage('stream.flash.removed', ContextualFeedbackSeverity::INFO);
            }
        }
    }

    public function processCmdmap_postProcess(string $command, string $table, int|string $id, mixed $value, DataHandler $dataHandler): void
    {
        if ($table !== self::MEDIA_TABLE) {
            return;
        }
        if ($command === 'copy') {
            $this->resetCopy((int)($dataHandler->copyMappingArray[self::MEDIA_TABLE][(int)$id] ?? 0));
            return;
        }
        if ($command !== 'delete') {
            return;
        }
        $record = $this->repository->findRecordIncludingDeleted((int)$id);
        if ($record === null || (int)($record['deleted'] ?? 0) !== 1 || (int)$record['sys_language_uid'] !== 0) {
            return;
        }

        $sourceUid = (int)($record['stream_source'] ?? 0);
        if ($sourceUid > 0) {
            $original = $this->repository->findRecord($sourceUid);
            if ($original !== null && (int)($original['stream_record'] ?? 0) === (int)$record['uid']) {
                $this->outputStorage->delete((string)($original['stream_output_folder'] ?? ''));
                $this->repository->updateRecord($sourceUid, ['stream_convert' => 0, ...$this->resetFields()]);
            }
            return;
        }

        if ($this->hasStreamingState($record)) {
            $this->removeVariant($record);
        }
    }

    protected function getCollectedTable(): string
    {
        return self::MEDIA_TABLE;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function queue(array $record): void
    {
        $status = StreamStatus::fromRecordValue($record['stream_status'] ?? '');
        if ($status === StreamStatus::Processing) {
            // The runner compares signatures when it finishes and re-queues itself.
            return;
        }

        $uid = (int)$record['uid'];
        $sources = $this->sourceCollector->collect($uid);
        if (StreamSourceCollector::findMain($sources) === null) {
            $message = $this->translate('stream.error.noLocalVideo');
            if ($status !== StreamStatus::Failed || (string)($record['stream_error'] ?? '') !== $message) {
                $this->repository->updateRecord($uid, ['stream_status' => StreamStatus::Failed->value, 'stream_error' => $message]);
            }
            $this->addFlashMessage('stream.error.noLocalVideo', ContextualFeedbackSeverity::WARNING);
            return;
        }

        $signature = $this->sourceCollector->signature(StreamFormat::fromRecordValue($record['stream_format'] ?? ''), $sources);
        $unchanged = $signature === (string)($record['stream_signature'] ?? '');
        if ($status === StreamStatus::Pending || ($status === StreamStatus::Done && $unchanged)) {
            return;
        }

        $this->repository->updateRecord($uid, ['stream_status' => StreamStatus::Pending->value, 'stream_error' => '']);
        $this->addFlashMessage('stream.flash.queued', ContextualFeedbackSeverity::INFO);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function removeVariant(array $record): void
    {
        $variantUid = (int)($record['stream_record'] ?? 0);
        $folder = (string)($record['stream_output_folder'] ?? '');
        // Reset first: deleting the variant re-enters processCmdmap_postProcess.
        $this->repository->updateRecord((int)$record['uid'], $this->resetFields());
        if ($variantUid > 0) {
            $this->variantService->delete($variantUid);
        }
        $this->outputStorage->delete($folder);
    }

    /**
     * A copy is a new video of its own: it starts unconverted, and the copied
     * "convert" flag must not queue it (TYPO3 14 dropped ctrl/setToDefaultOnCopy).
     */
    private function resetCopy(int $copyUid): void
    {
        if ($copyUid <= 0) {
            return;
        }
        $fields = ['stream_convert' => 0, ...$this->resetFields()];
        $this->repository->updateRecord($copyUid, $fields, false);
        foreach ($this->repository->findTranslations($copyUid) as $translation) {
            $this->repository->updateRecord((int)$translation['uid'], $fields, false);
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function hasStreamingState(array $record): bool
    {
        return (int)($record['stream_record'] ?? 0) > 0
            || (string)($record['stream_output_folder'] ?? '') !== ''
            || StreamStatus::fromRecordValue($record['stream_status'] ?? '') !== StreamStatus::None;
    }

    /**
     * @return array<string, int|string>
     */
    private function resetFields(): array
    {
        return [
            'stream_status' => StreamStatus::None->value,
            'stream_error' => '',
            'stream_record' => 0,
            'stream_signature' => '',
            'stream_output_folder' => '',
            'stream_converted_at' => 0,
            'stream_ffmpeg_version' => '',
        ];
    }

    private function addFlashMessage(string $key, ContextualFeedbackSeverity $severity): void
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface || !ApplicationType::fromRequest($request)->isBackend()) {
            return;
        }

        $this->flashMessageService
            ->getMessageQueueByIdentifier(FlashMessageQueue::NOTIFICATION_QUEUE)
            ->enqueue(new FlashMessage(
                $this->translate($key),
                $this->translate($key . '.title'),
                $severity,
                true
            ));
    }

    private function translate(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if ($languageService instanceof LanguageService) {
            $label = $languageService->sL(self::LABELS . $key);
            if ($label !== '') {
                return $label;
            }
        }

        return $key;
    }
}
