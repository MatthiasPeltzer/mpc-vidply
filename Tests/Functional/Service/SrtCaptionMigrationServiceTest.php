<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\Service;

use Mpc\MpcVidply\Dto\FileConversionResult;
use Mpc\MpcVidply\Service\SrtCaptionMigrationService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SrtCaptionMigrationServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mpc/mpc-vidply'];

    private const SRT_CONTENT = "1\n00:00:01,000 --> 00:00:04,000\nHello world\n";

    private SrtCaptionMigrationService $subject;
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

        $this->subject = GeneralUtility::makeInstance(SrtCaptionMigrationService::class);
    }

    private function createSrtFileReference(string $fileName = 'captions.srt', int $mediaUid = 1): int
    {
        $folder = $this->storage->getRootLevelFolder();
        $file = $this->storage->createFile($fileName, $folder);
        $file->setContents(self::SRT_CONTENT);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        $connection->insert('sys_file_reference', [
            'pid' => 1,
            'tablenames' => 'tx_mpcvidply_media',
            'fieldname' => 'captions',
            'uid_local' => $file->getUid(),
            'uid_foreign' => $mediaUid,
        ]);

        return (int)$connection->lastInsertId();
    }

    #[Test]
    public function findAllSrtReferencesReturnsLinkedSrtFile(): void
    {
        $referenceUid = $this->createSrtFileReference();

        $references = $this->subject->findAllSrtReferences();

        self::assertCount(1, $references);
        self::assertSame($referenceUid, $references[0]['file_reference_uid']);
        self::assertSame('captions.srt', $references[0]['file_name']);
        self::assertSame('tx_mpcvidply_media', $references[0]['tablenames']);
        self::assertSame('captions', $references[0]['fieldname']);
        self::assertSame(1, $references[0]['media_uid']);
    }

    #[Test]
    public function convertFileReferenceByUidSkipsInvalidUid(): void
    {
        $result = $this->subject->convertFileReferenceByUid(0);

        self::assertSame(FileConversionResult::STATUS_SKIPPED, $result->status);
    }

    #[Test]
    public function dryRunReportsConversionWithoutModifyingFile(): void
    {
        $referenceUid = $this->createSrtFileReference();

        $result = $this->subject->convertFileReferenceByUid($referenceUid, true);

        self::assertSame(FileConversionResult::STATUS_CONVERTED, $result->status);
        self::assertSame('captions.srt', $result->originalFileName);
        self::assertSame('captions.vtt', $result->newFileName);

        // The original file is untouched in a dry run.
        $reference = GeneralUtility::makeInstance(ResourceFactory::class)->getFileReferenceObject($referenceUid);
        $originalFile = $reference->getOriginalFile();
        self::assertInstanceOf(File::class, $originalFile);
        self::assertSame('srt', $originalFile->getExtension());
        self::assertSame(self::SRT_CONTENT, $originalFile->getContents());
    }

    #[Test]
    public function convertRenamesFileToVttAndRewritesContents(): void
    {
        $referenceUid = $this->createSrtFileReference();

        $result = $this->subject->convertFileReferenceByUid($referenceUid, false);

        self::assertSame(FileConversionResult::STATUS_CONVERTED, $result->status);
        self::assertSame('captions.vtt', $result->newFileName);

        $reference = GeneralUtility::makeInstance(ResourceFactory::class)->getFileReferenceObject($referenceUid);
        $originalFile = $reference->getOriginalFile();
        self::assertSame('vtt', $originalFile->getExtension());
        self::assertStringStartsWith("WEBVTT\n", $originalFile->getContents());
        self::assertStringContainsString('00:00:01.000 --> 00:00:04.000', $originalFile->getContents());
    }

    #[Test]
    public function migrateAllConvertsEveryLinkedSrtReference(): void
    {
        $this->createSrtFileReference('first.srt', 1);
        $this->createSrtFileReference('second.srt', 2);

        $batch = $this->subject->migrateAll(false);

        self::assertSame(2, $batch->convertedCount());
        self::assertSame(0, $batch->failedCount());
    }
}
