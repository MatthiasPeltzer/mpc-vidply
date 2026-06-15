<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Command;

use Mpc\MpcVidply\Dto\FileConversionResult;
use Mpc\MpcVidply\Service\SrtCaptionMigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ConvertSrtCaptionsCommand extends Command
{
    public function __construct(
        private readonly SrtCaptionMigrationService $migrationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Convert VidPly-linked SRT subtitle files to WebVTT')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'List files that would be converted without writing changes'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of files to process'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool)$input->getOption('dry-run');
        $limitOption = $input->getOption('limit');
        $limit = is_numeric($limitOption) ? (int)$limitOption : null;

        if ($dryRun) {
            $io->note('Dry run — no files will be modified.');
        }

        $batchResult = $this->migrationService->migrateAll($dryRun, $limit);

        foreach ($batchResult->results as $result) {
            match ($result->status) {
                FileConversionResult::STATUS_CONVERTED => $io->writeln(sprintf(
                    '[converted] %s -> %s',
                    $result->originalFileName,
                    $result->newFileName
                )),
                FileConversionResult::STATUS_SKIPPED => $io->writeln(sprintf(
                    '[skipped] %s (%s)',
                    $result->originalFileName !== '' ? $result->originalFileName : 'unknown',
                    $result->message
                )),
                FileConversionResult::STATUS_FAILED => $io->error(sprintf(
                    '[failed] %s (%s)',
                    $result->originalFileName,
                    $result->message
                )),
                default => null,
            };
        }

        $io->success(sprintf(
            'Done. Converted: %d, skipped: %d, failed: %d',
            $batchResult->convertedCount(),
            $batchResult->skippedCount(),
            $batchResult->failedCount()
        ));

        return $batchResult->failedCount() > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
