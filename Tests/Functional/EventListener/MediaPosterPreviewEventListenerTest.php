<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\EventListener;

use Mpc\MpcVidply\EventListener\MediaPosterPreviewEventListener;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\OnlineMedia\Event\AfterVideoPreviewFetchedEvent;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperInterface;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class MediaPosterPreviewEventListenerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mpc/mpc-vidply'];

    private const HELPER_PREVIEW = '/helper/preview.svg';

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private ResourceStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/MediaRecords.csv');

        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin');

        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
        $storageId = $storageRepository->createLocalStorage('Test', 'fileadmin/', 'relative', 'Test storage', true);
        $storage = $storageRepository->findByUid($storageId);
        self::assertInstanceOf(ResourceStorage::class, $storage);
        $storage->setEvaluatePermissions(false);
        $this->storage = $storage;
    }

    #[Test]
    public function usesPosterOfMediaRecordForVidPlyContainerFile(): void
    {
        $container = $this->createFile('stream.hls', 'https://cdn.example.com/live/master.m3u8');
        $poster = $this->createFile('poster.png', (string)base64_decode(self::PNG_1X1, true));
        $this->reference($container, 1, 'media_file');
        $this->reference($poster, 1, 'poster');

        $event = $this->dispatch($container);

        self::assertSame($poster->getForLocalProcessing(false), $event->getPreviewImageFilename());
    }

    #[Test]
    public function keepsHelperPreviewForCoreOnlineMedia(): void
    {
        $container = $this->createFile('clip.youtube', 'dQw4w9WgXcQ');
        $poster = $this->createFile('poster.png', (string)base64_decode(self::PNG_1X1, true));
        $this->reference($container, 1, 'media_file');
        $this->reference($poster, 1, 'poster');

        self::assertSame(self::HELPER_PREVIEW, $this->dispatch($container)->getPreviewImageFilename());
    }

    #[Test]
    public function keepsHelperPreviewWhenNoRecordHasAPoster(): void
    {
        $container = $this->createFile('stream.hls', 'https://cdn.example.com/live/master.m3u8');
        $this->reference($container, 1, 'media_file');

        self::assertSame(self::HELPER_PREVIEW, $this->dispatch($container)->getPreviewImageFilename());
    }

    #[Test]
    public function keepsHelperPreviewWhenPosterIsNoImage(): void
    {
        $container = $this->createFile('stream.hls', 'https://cdn.example.com/live/master.m3u8');
        $poster = $this->createFile('notes.txt', 'not an image');
        $this->reference($container, 1, 'media_file');
        $this->reference($poster, 1, 'poster');

        self::assertSame(self::HELPER_PREVIEW, $this->dispatch($container)->getPreviewImageFilename());
    }

    private function createFile(string $fileName, string $contents): File
    {
        $file = $this->storage->createFile($fileName, $this->storage->getRootLevelFolder());
        $file->setContents($contents);

        return $file;
    }

    private function reference(File $file, int $mediaUid, string $fieldName): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference')
            ->insert('sys_file_reference', [
                'pid' => 1,
                'tablenames' => 'tx_mpcvidply_media',
                'fieldname' => $fieldName,
                'uid_local' => $file->getUid(),
                'uid_foreign' => $mediaUid,
            ]);
    }

    private function dispatch(File $file): AfterVideoPreviewFetchedEvent
    {
        $event = new AfterVideoPreviewFetchedEvent(
            $file,
            self::createStub(OnlineMediaHelperInterface::class),
            self::HELPER_PREVIEW,
        );
        $this->get(MediaPosterPreviewEventListener::class)($event);

        return $event;
    }
}
