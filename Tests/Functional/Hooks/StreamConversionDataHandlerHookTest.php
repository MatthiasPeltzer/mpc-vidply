<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\Hooks;

use Mpc\MpcVidply\Enums\StreamFormat;
use Mpc\MpcVidply\Enums\StreamStatus;
use Mpc\MpcVidply\Service\Streaming\StreamSourceCollector;
use Mpc\MpcVidply\Tests\Functional\MpcVidplyFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class StreamConversionDataHandlerHookTest extends MpcVidplyFunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mpc/mpc-vidply'];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'mpc_vidply' => [
                'streamingEnabled' => '1',
            ],
        ],
    ];

    private const TABLE = 'tx_mpcvidply_media';
    private const VARIANT_UID = 50;

    private ResourceStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendAdmin.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/MediaRecords.csv');
        $this->setUpBackendUser(1);

        // The test instance and its fileadmin/ are shared by all tests of this class.
        GeneralUtility::rmdir(Environment::getPublicPath() . '/fileadmin', true);
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin');
        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
        $storageId = $storageRepository->createLocalStorage('Test', 'fileadmin/', 'relative', 'Test storage', true);
        $storage = $storageRepository->findByUid($storageId);
        self::assertInstanceOf(ResourceStorage::class, $storage);
        $storage->setEvaluatePermissions(false);
        $this->storage = $storage;

        $video = $this->storage->createFile('clip.mp4', $this->storage->getRootLevelFolder());
        $video->setContents('not really a video');
        $this->reference($video, 1, 'media_file');
    }

    #[Test]
    public function enablingTheConversionQueuesTheRecord(): void
    {
        $this->processDatamap([self::TABLE => [1 => ['stream_convert' => 1]]]);

        self::assertSame(StreamStatus::Pending->value, $this->record(1)['stream_status']);
    }

    #[Test]
    public function aRecordWithoutLocalVideoIsMarkedFailed(): void
    {
        $this->processDatamap([self::TABLE => [6 => ['stream_convert' => 1]]]);

        $record = $this->record(6);
        self::assertSame(StreamStatus::Failed->value, $record['stream_status']);
        self::assertNotSame('', (string)$record['stream_error']);
    }

    #[Test]
    public function editingTheTitleOfAConvertedRecordDoesNotQueueIt(): void
    {
        $this->markConverted();

        $this->processDatamap([self::TABLE => [1 => ['title' => 'Clip One, renamed']]]);

        self::assertSame(StreamStatus::Done->value, $this->record(1)['stream_status']);
    }

    #[Test]
    public function changingTheFormatOfAConvertedRecordQueuesItAgain(): void
    {
        $this->markConverted();

        $this->processDatamap([self::TABLE => [1 => ['stream_format' => StreamFormat::Dash->value]]]);

        self::assertSame(StreamStatus::Pending->value, $this->record(1)['stream_status']);
    }

    #[Test]
    public function disablingTheConversionRemovesTheVariantAndItsFiles(): void
    {
        $outputDirectory = $this->markConverted();

        $this->processDatamap([self::TABLE => [1 => ['stream_convert' => 0]]]);

        $original = $this->record(1);
        self::assertSame(StreamStatus::None->value, $original['stream_status']);
        self::assertSame(0, (int)$original['stream_record']);
        self::assertSame('', $original['stream_output_folder']);
        self::assertSame(1, (int)$this->record(self::VARIANT_UID)['deleted']);
        self::assertDirectoryDoesNotExist($outputDirectory);
    }

    #[Test]
    public function deletingTheOriginalRemovesTheVariant(): void
    {
        $outputDirectory = $this->markConverted();

        $this->processCmdmap([self::TABLE => [1 => ['delete' => 1]]]);

        self::assertSame(1, (int)$this->record(self::VARIANT_UID)['deleted']);
        self::assertDirectoryDoesNotExist($outputDirectory);
    }

    #[Test]
    public function deletingTheVariantTurnsTheConversionOffOnTheOriginal(): void
    {
        $this->markConverted();

        $this->processCmdmap([self::TABLE => [self::VARIANT_UID => ['delete' => 1]]]);

        $original = $this->record(1);
        self::assertSame(0, (int)$original['stream_convert']);
        self::assertSame(StreamStatus::None->value, $original['stream_status']);
        self::assertSame(0, (int)$original['stream_record']);
    }

    #[Test]
    public function aCopyOfAConvertedRecordStartsUnconverted(): void
    {
        $this->markConverted();

        $dataHandler = $this->processCmdmap([self::TABLE => [1 => ['copy' => 1]]]);

        $copyUid = (int)($dataHandler->copyMappingArray[self::TABLE][1] ?? 0);
        self::assertGreaterThan(0, $copyUid);
        $copy = $this->record($copyUid);
        self::assertSame(0, (int)$copy['stream_convert']);
        self::assertSame(StreamStatus::None->value, $copy['stream_status']);
        self::assertSame(0, (int)$copy['stream_record']);
        self::assertSame('', $copy['stream_output_folder']);
        self::assertSame(StreamStatus::Done->value, $this->record(1)['stream_status']);
    }

    /**
     * Record 1 converted to HLS with variant record 50 and an output folder.
     *
     * @return string Absolute path of the output folder
     */
    private function markConverted(): string
    {
        $folder = $this->storage->createFolder('clip-1', $this->storage->createFolder('streams'));
        $this->storage->createFile('clip.m3u8', $folder)->setContents('#EXTM3U');

        $collector = $this->get(StreamSourceCollector::class);
        $signature = $collector->signature(StreamFormat::Hls, $collector->collect(1));

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'uid' => self::VARIANT_UID,
            'pid' => 1,
            'media_type' => 'video',
            'slug' => 'clip-one-stream',
            'title' => 'Clip One (Stream)',
            'stream_source' => 1,
        ]);
        $connection->update(self::TABLE, [
            'stream_convert' => 1,
            'stream_format' => StreamFormat::Hls->value,
            'stream_status' => StreamStatus::Done->value,
            'stream_signature' => $signature,
            'stream_record' => self::VARIANT_UID,
            'stream_output_folder' => $this->storage->getUid() . ':/streams/clip-1/',
        ], ['uid' => 1]);

        $directory = Environment::getPublicPath() . '/fileadmin/streams/clip-1';
        self::assertDirectoryExists($directory);

        return $directory;
    }

    /**
     * @return array<string, mixed>
     */
    private function record(int $uid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $uid))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $cmdmap
     */
    private function processCmdmap(array $cmdmap): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmdmap);
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog);

        return $dataHandler;
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     */
    private function processDatamap(array $datamap): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
    }

    private function reference(File $file, int $mediaUid, string $fieldName): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference')
            ->insert('sys_file_reference', [
                'pid' => 1,
                'tablenames' => self::TABLE,
                'fieldname' => $fieldName,
                'uid_local' => $file->getUid(),
                'uid_foreign' => $mediaUid,
            ]);
    }
}
