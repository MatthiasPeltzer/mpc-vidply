<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Command;

use Mpc\MpcVidply\Dto\StreamConversionResult;
use Mpc\MpcVidply\Enums\StreamStatus;
use Mpc\MpcVidply\Service\Streaming\StreamConversionService;
use Mpc\MpcVidply\Service\Streaming\StreamingSettings;
use Mpc\MpcVidply\Service\Streaming\StreamRecordRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Works through the streaming queue. Meant to run from the scheduler
 * ("Execute console commands") every few minutes; a lock keeps two runs
 * from encoding at the same time.
 */
final class StreamConvertCommand extends Command
{
    /** A run that has not finished after this long is considered dead. */
    private const STALE_PROCESSING_SECONDS = 6 * 3600;

    public function __construct(
        private readonly StreamConversionService $conversionService,
        private readonly StreamRecordRepository $repository,
        private readonly StreamingSettings $settings,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Convert queued VidPly videos to HLS/DASH streaming variants with ffmpeg')
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of records to convert in this run',
                '1'
            )
            ->addOption(
                'uid',
                null,
                InputOption::VALUE_REQUIRED,
                'Convert this media record now, whether queued or not (the record must have "Convert to stream" enabled)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->settings->isEnabled()) {
            $io->warning('Streaming conversion is disabled in the extension configuration of mpc_vidply (streamingEnabled).');
            return Command::SUCCESS;
        }

        $locker = $this->lockFactory->createLocker(
            'mpc_vidply_stream_convert',
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK
        );
        try {
            $locker->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK);
        } catch (LockAcquireWouldBlockException) {
            $io->note('Another conversion run is still in progress.');
            return Command::SUCCESS;
        }

        try {
            Bootstrap::initializeBackendAuthentication();

            foreach ($this->repository->findStaleProcessingUids(self::STALE_PROCESSING_SECONDS) as $staleUid) {
                $this->repository->updateRecord($staleUid, ['stream_status' => StreamStatus::Pending->value]);
                $io->writeln(sprintf('[requeued] #%d (previous run did not finish)', $staleUid));
            }

            $uidOption = $input->getOption('uid');
            $limitOption = $input->getOption('limit');
            $uids = is_numeric($uidOption)
                ? [(int)$uidOption]
                : $this->repository->findPendingUids(is_numeric($limitOption) ? max(1, (int)$limitOption) : 1);

            if ($uids === []) {
                $io->writeln('Nothing to convert.');
                return Command::SUCCESS;
            }

            $failed = 0;
            foreach ($uids as $uid) {
                $io->writeln(sprintf('Converting media record #%d …', $uid));
                $result = $this->conversionService->convert($uid);
                match ($result->status) {
                    StreamConversionResult::STATUS_CONVERTED => $io->writeln(sprintf('[converted] #%d → streaming variant #%d', $uid, $result->variantUid)),
                    StreamConversionResult::STATUS_REQUEUED => $io->writeln(sprintf('[requeued] #%d → #%d (%s)', $uid, $result->variantUid, $result->message)),
                    StreamConversionResult::STATUS_SKIPPED => $io->writeln(sprintf('[skipped] #%d (%s)', $uid, $result->message)),
                    default => $io->error(sprintf('[failed] #%d: %s', $uid, $result->message)),
                };
                if ($result->status === StreamConversionResult::STATUS_FAILED) {
                    $failed++;
                }
            }

            return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
        } finally {
            $locker->release();
        }
    }
}
