<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\Command;

use Mpc\MpcVidply\Command\ConvertSrtCaptionsCommand;
use Mpc\MpcVidply\Service\SrtCaptionMigrationService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ConvertSrtCaptionsCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mpc/mpc-vidply'];

    private const SRT_CONTENT = "1\n00:00:01,000 --> 00:00:04,000\nHello world\n";

    private CommandTester $commandTester;
    private ResourceStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/MediaRecords.csv');

        $fileadmin = Environment::getPublicPath() . '/fileadmin';
        GeneralUtility::mkdir_deep($fileadmin);

        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
        $storageId = $storageRepository->createLocalStorage('Test', 'fileadmin/', 'relative', 'Test storage', true);
        $storage = $storageRepository->findByUid($storageId);
        self::assertInstanceOf(ResourceStorage::class, $storage);
        $storage->setEvaluatePermissions(false);
        $this->storage = $storage;

        $service = GeneralUtility::makeInstance(SrtCaptionMigrationService::class);
        $this->commandTester = new CommandTester(new ConvertSrtCaptionsCommand($service));

        $this->createSrtReference();
    }

    private function createSrtReference(string $fileName = 'captions.srt', int $mediaUid = 1): void
    {
        $folder = $this->storage->getRootLevelFolder();
        $file = $this->storage->createFile($fileName, $folder);
        $file->setContents(self::SRT_CONTENT);

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference')
            ->insert('sys_file_reference', [
                'pid' => 1,
                'tablenames' => 'tx_mpcvidply_media',
                'fieldname' => 'captions',
                'uid_local' => $file->getUid(),
                'uid_foreign' => $mediaUid,
            ]);
    }

    #[Test]
    public function dryRunReportsConversionAndKeepsExitCodeSuccessful(): void
    {
        $exitCode = $this->commandTester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Dry run', $display);
        self::assertStringContainsString('captions.srt -> captions.vtt', $display);
        self::assertStringContainsString('Converted: 1', $display);
    }

    #[Test]
    public function executeConvertsReferencedFiles(): void
    {
        $exitCode = $this->commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Converted: 1, skipped: 0, failed: 0', $this->commandTester->getDisplay());
    }
}
