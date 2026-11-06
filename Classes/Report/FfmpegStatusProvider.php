<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Report;

use Mpc\MpcVidply\Service\Streaming\FfmpegVersionChecker;
use Mpc\MpcVidply\Service\Streaming\StreamingSettings;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

/**
 * "VidPly streaming" section in Reports → Status Report: whether ffmpeg and
 * ffprobe run and meet the minimum version. Registered in
 * Configuration/Services.php only when EXT:reports is installed.
 */
final readonly class FfmpegStatusProvider implements StatusProviderInterface
{
    private const LABELS = 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:';

    public function __construct(
        private FfmpegVersionChecker $versionChecker,
        private StreamingSettings $settings,
    ) {}

    public function getLabel(): string
    {
        return 'VidPly streaming (ffmpeg)';
    }

    /**
     * @return list<Status>
     */
    public function getStatus(): array
    {
        if (!$this->settings->isEnabled()) {
            return [new Status(
                $this->label('report.ffmpeg.title'),
                $this->label('report.ffmpeg.disabled'),
                $this->label('report.ffmpeg.disabled.message'),
                ContextualFeedbackSeverity::INFO
            )];
        }

        $status = $this->versionChecker->check();
        $statuses = [];

        if (!$status->ffmpegAvailable) {
            $statuses[] = new Status(
                $this->label('report.ffmpeg.title'),
                $this->label('report.ffmpeg.notFound'),
                sprintf($this->label('report.ffmpeg.notFound.message'), htmlspecialchars($status->ffmpegPath), htmlspecialchars($status->error)),
                ContextualFeedbackSeverity::ERROR
            );
        } elseif ($status->isTooOld()) {
            $statuses[] = new Status(
                $this->label('report.ffmpeg.title'),
                (string)$status->ffmpegVersion,
                sprintf($this->label('report.ffmpeg.tooOld.message'), htmlspecialchars($status->minimumVersion)),
                ContextualFeedbackSeverity::ERROR
            );
        } elseif ($status->isVersionUnknown()) {
            $statuses[] = new Status(
                $this->label('report.ffmpeg.title'),
                $this->label('report.ffmpeg.unknownVersion'),
                sprintf($this->label('report.ffmpeg.unknownVersion.message'), htmlspecialchars($status->minimumVersion)),
                ContextualFeedbackSeverity::WARNING
            );
        } else {
            $statuses[] = new Status(
                $this->label('report.ffmpeg.title'),
                (string)$status->ffmpegVersion,
                sprintf($this->label('report.ffmpeg.ok.message'), htmlspecialchars($status->ffmpegPath)),
                ContextualFeedbackSeverity::OK
            );
        }

        $statuses[] = new Status(
            $this->label('report.ffprobe.title'),
            $status->ffprobeAvailable
                ? (string)($status->ffprobeVersion ?? $this->label('report.ffmpeg.unknownVersion'))
                : $this->label('report.ffmpeg.notFound'),
            $status->ffprobeAvailable ? '' : sprintf($this->label('report.ffmpeg.notFound.message'), htmlspecialchars($status->ffprobePath), ''),
            $status->ffprobeAvailable ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::ERROR
        );

        return $statuses;
    }

    private function label(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;

        return $languageService instanceof LanguageService ? $languageService->sL(self::LABELS . $key) : $key;
    }
}
