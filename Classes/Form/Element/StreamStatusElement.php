<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Form\Element;

use Mpc\MpcVidply\Enums\StreamFormat;
use Mpc\MpcVidply\Enums\StreamStatus;
use Mpc\MpcVidply\Service\Streaming\FfmpegVersionChecker;
use Mpc\MpcVidply\Service\Streaming\StreamRecordRepository;
use Mpc\MpcVidply\Utility\RecordAwareValueResolver;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Read-only box showing the conversion state of a media record, a link to
 * its streaming variant, and a warning when ffmpeg is missing or too old.
 */
final class StreamStatusElement extends AbstractFormElement
{
    private const LABELS = 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:';

    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        $resultArray = $this->initializeResultArray();
        $uid = RecordAwareValueResolver::resolveInt($this->data['databaseRow']['uid'] ?? null);
        $repository = GeneralUtility::makeInstance(StreamRecordRepository::class);
        $record = $uid > 0 ? $repository->findRecord($uid) : null;

        $html = (int)($record['stream_source'] ?? 0) > 0
            ? $this->renderVariant($record ?? [], $repository)
            : $this->renderOriginal($record ?? [], $repository);

        $resultArray['html'] = $this->wrapWithFieldsetAndLegend('<div class="formengine-field-item t3js-formengine-field-item">' . $html . '</div>');

        return $resultArray;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function renderOriginal(array $record, StreamRecordRepository $repository): string
    {
        $status = StreamStatus::fromRecordValue($record['stream_status'] ?? '');
        $html = $this->renderFfmpegWarning();

        [$severity, $key] = match ($status) {
            StreamStatus::Pending => ['info', 'stream.status.pending'],
            StreamStatus::Processing => ['info', 'stream.status.processing'],
            StreamStatus::Done => ['success', 'stream.status.done'],
            StreamStatus::Failed => ['danger', 'stream.status.failed'],
            StreamStatus::None => ['info', 'stream.status.none'],
        };

        $body = '<p class="mb-0">' . $this->label($key) . '</p>';
        if ($status === StreamStatus::Failed && trim((string)($record['stream_error'] ?? '')) !== '') {
            $body .= '<pre class="mt-2 mb-0 small text-wrap">' . htmlspecialchars((string)$record['stream_error']) . '</pre>';
        }
        $body .= $this->renderDetails($record);

        $variant = $repository->findRecord((int)($record['stream_record'] ?? 0));
        if ($variant !== null) {
            $body .= '<p class="mt-2 mb-0">' . sprintf(
                $this->label('stream.status.variantLink'),
                $this->editLink((int)$variant['uid'], (string)$variant['title'])
            ) . '</p>';
        }

        return $html . '<div class="alert alert-' . $severity . ' mb-0" role="status">' . $body . '</div>';
    }

    /**
     * @param array<string, mixed> $record
     */
    private function renderVariant(array $record, StreamRecordRepository $repository): string
    {
        $original = $repository->findRecord((int)$record['stream_source']);
        $originalLink = $original !== null
            ? $this->editLink((int)$original['uid'], (string)$original['title'])
            : htmlspecialchars('#' . (int)$record['stream_source']);

        return '<div class="alert alert-info mb-0" role="status">'
            . '<p class="mb-0">' . sprintf($this->label('stream.status.isVariant'), $originalLink) . '</p>'
            . $this->renderDetails($record)
            . '</div>';
    }

    /**
     * @param array<string, mixed> $record
     */
    private function renderDetails(array $record): string
    {
        $items = [];
        $convertedAt = (int)($record['stream_converted_at'] ?? 0);
        if ($convertedAt > 0) {
            $items[] = [$this->label('stream.status.convertedAt'), htmlspecialchars(BackendUtility::datetime($convertedAt))];
            $items[] = [
                $this->label('stream.status.format'),
                $this->label('stream.format.' . StreamFormat::fromRecordValue($record['stream_format'] ?? '')->value),
            ];
        }
        $version = trim((string)($record['stream_ffmpeg_version'] ?? ''));
        if ($version !== '') {
            $items[] = [$this->label('stream.status.ffmpegVersion'), htmlspecialchars($version)];
        }
        if ($items === []) {
            return '';
        }

        $html = '<dl class="row mt-2 mb-0 small">';
        foreach ($items as [$term, $value]) {
            $html .= '<dt class="col-sm-4">' . $term . '</dt><dd class="col-sm-8 mb-0">' . $value . '</dd>';
        }

        return $html . '</dl>';
    }

    private function renderFfmpegWarning(): string
    {
        $status = GeneralUtility::makeInstance(FfmpegVersionChecker::class)->check();
        if ($status->isUsable() && !$status->isVersionUnknown()) {
            return '';
        }

        if (!$status->ffmpegAvailable || !$status->ffprobeAvailable) {
            $message = sprintf($this->label('stream.ffmpeg.missing'), htmlspecialchars($status->ffmpegPath), htmlspecialchars($status->ffprobePath));
            $severity = 'danger';
        } elseif ($status->isTooOld()) {
            $message = sprintf($this->label('stream.ffmpeg.tooOld'), htmlspecialchars((string)$status->ffmpegVersion), htmlspecialchars($status->minimumVersion));
            $severity = 'danger';
        } else {
            $message = sprintf($this->label('stream.ffmpeg.unknownVersion'), htmlspecialchars($status->minimumVersion));
            $severity = 'warning';
        }

        return '<div class="alert alert-' . $severity . ' mb-2" role="alert"><p class="mb-0">' . $message . '</p></div>';
    }

    private function editLink(int $uid, string $title): string
    {
        $uri = (string)GeneralUtility::makeInstance(UriBuilder::class)->buildUriFromRoute('record_edit', [
            'edit' => [StreamRecordRepository::MEDIA_TABLE => [$uid => 'edit']],
            'returnUrl' => (string)($this->data['returnUrl'] ?? ''),
        ]);

        return '<a href="' . htmlspecialchars($uri) . '">' . htmlspecialchars($title !== '' ? $title : '#' . $uid) . '</a>';
    }

    private function label(string $key): string
    {
        return htmlspecialchars($this->getLanguageService()->sL(self::LABELS . $key));
    }
}
