<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\Hooks;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Index\Indexer;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class MediaPosterThumbnailHookTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mpc/mpc-vidply'];

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private const PAST = 1700000000;

    private ResourceStorage $storage;

    private File $container;

    private File $poster;

    private int $posterReferenceUid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendAdmin.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/MediaRecords.csv');
        $this->setUpBackendUser(1);

        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin');
        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
        $storageId = $storageRepository->createLocalStorage('Test', 'fileadmin/', 'relative', 'Test storage', true);
        $storage = $storageRepository->findByUid($storageId);
        self::assertInstanceOf(ResourceStorage::class, $storage);
        $storage->setEvaluatePermissions(false);
        $this->storage = $storage;

        $this->container = $this->createFile('stream.hls', 'https://cdn.example.com/live/master.m3u8');
        $this->poster = $this->createFile('poster.png', (string)base64_decode(self::PNG_1X1, true));
        $this->reference($this->container, 1, 'media_file');
        $this->posterReferenceUid = $this->reference($this->poster, 1, 'poster');

        touch($this->container->getForLocalProcessing(false), self::PAST);
        clearstatcache();
        GeneralUtility::makeInstance(Indexer::class, $storage)->updateIndexEntry($this->container);
        self::assertSame(self::PAST, $this->containerModificationDate());
    }

    #[Test]
    public function removingThePosterRenewsTheContainerThumbnail(): void
    {
        $this->processCmdmap(['sys_file_reference' => [$this->posterReferenceUid => ['delete' => 1]]]);

        self::assertGreaterThan(self::PAST, $this->containerModificationDate());
    }

    #[Test]
    public function addingAPosterRenewsTheContainerThumbnail(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference')
            ->delete('sys_file_reference', ['uid' => $this->posterReferenceUid]);

        $this->processDatamap([
            'sys_file_reference' => [
                'NEW1' => ['pid' => 1, 'uid_local' => $this->poster->getUid()],
            ],
            'tx_mpcvidply_media' => [1 => ['poster' => 'NEW1']],
        ]);

        self::assertGreaterThan(self::PAST, $this->containerModificationDate());
    }

    #[Test]
    public function savingWithoutPosterChangeKeepsTheContainerThumbnail(): void
    {
        $this->processDatamap(['tx_mpcvidply_media' => [1 => ['title' => 'Renamed clip']]]);

        self::assertSame(self::PAST, $this->containerModificationDate());
    }

    #[Test]
    public function deletingTheRecordRenewsTheContainerThumbnail(): void
    {
        $this->processCmdmap(['tx_mpcvidply_media' => [1 => ['delete' => 1]]]);

        self::assertGreaterThan(self::PAST, $this->containerModificationDate());
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $cmdmap
     */
    private function processCmdmap(array $cmdmap): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmdmap);
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog);
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

    private function containerModificationDate(): int
    {
        return (int)GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->select(['modification_date'], 'sys_file', ['uid' => $this->container->getUid()])
            ->fetchOne();
    }

    private function createFile(string $fileName, string $contents): File
    {
        $file = $this->storage->createFile($fileName, $this->storage->getRootLevelFolder());
        $file->setContents($contents);

        return $file;
    }

    private function reference(File $file, int $mediaUid, string $fieldName): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        $connection->insert('sys_file_reference', [
            'pid' => 1,
            'tablenames' => 'tx_mpcvidply_media',
            'fieldname' => $fieldName,
            'uid_local' => $file->getUid(),
            'uid_foreign' => $mediaUid,
        ]);

        return (int)$connection->lastInsertId();
    }
}
