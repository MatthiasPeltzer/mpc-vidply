<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\DataProcessing;

use Mpc\MpcVidply\DataProcessing\VidPlyProcessor;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class VidPlyProcessorTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mpc/mpc-vidply'];

    private VidPlyProcessor $subject;
    private ResourceStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $fileadmin = Environment::getPublicPath() . '/fileadmin';
        GeneralUtility::mkdir_deep($fileadmin);

        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
        $storageId = $storageRepository->createLocalStorage('Test', 'fileadmin/', 'relative', 'Test storage', true);
        $storage = $storageRepository->findByUid($storageId);
        self::assertInstanceOf(ResourceStorage::class, $storage);
        $storage->setEvaluatePermissions(false);
        $this->storage = $storage;

        $this->subject = GeneralUtility::makeInstance(VidPlyProcessor::class);
    }

    private function attachMediaFile(int $mediaUid, string $fileName, string $contents): void
    {
        $folder = $this->storage->getRootLevelFolder();
        $file = $this->storage->createFile($fileName, $folder);
        $file->setContents($contents);

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference')
            ->insert('sys_file_reference', [
                'pid' => 1,
                'tablenames' => 'tx_mpcvidply_media',
                'fieldname' => 'media_file',
                'uid_local' => $file->getUid(),
                'uid_foreign' => $mediaUid,
                'sorting_foreign' => 1,
            ]);
    }

    private function request(): ServerRequestInterface
    {
        return new ServerRequest('https://example.com/');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function mediaRecord(array $overrides): array
    {
        return array_merge([
            'uid' => 1,
            'pid' => 1,
            'sys_language_uid' => 0,
            'title' => 'A clip',
            'media_type' => 'video',
            'audio_description_mode' => 'auto',
        ], $overrides);
    }

    #[Test]
    public function assemblesVideoRenderModeForLocalVideo(): void
    {
        $this->attachMediaFile(1, 'clip.mp4', 'fake-mp4-bytes');

        $record = $this->mediaRecord(['uid' => 1, 'media_type' => 'video', 'title' => 'Local Clip']);
        $result = $this->subject->assembleForMediaRecords([$record], ['uid' => 1], $this->request(), 0);

        self::assertSame('video', $result['renderMode']);
        self::assertSame('video', $result['mediaType']);
        self::assertFalse($result['hasExternalMedia']);
        self::assertNotEmpty($result['tracks']);
        self::assertStringContainsString('clip.mp4', (string)$result['tracks'][0]['src']);
        self::assertIsArray($result['options']);
        self::assertArrayHasKey('controls', $result['options']);
    }

    #[Test]
    public function assemblesPrivacyRenderModeForYouTubeRecord(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PrivacySettings.csv');
        $this->attachMediaFile(2, 'clip.youtube', 'dQw4w9WgXcQ');

        $record = $this->mediaRecord(['uid' => 2, 'media_type' => 'youtube', 'title' => 'YouTube Clip']);
        $result = $this->subject->assembleForMediaRecords([$record], ['uid' => 2], $this->request(), 0);

        self::assertSame('privacy', $result['renderMode']);
        self::assertSame('youtube', $result['serviceType']);
        self::assertTrue($result['hasExternalMedia']);
        self::assertArrayHasKey('youtube', $result['privacySettings']);
        self::assertSame('Watch on YouTube', $result['privacySettings']['youtube']['headline']);
    }

    #[Test]
    public function buildsMixedPlaylistRenderModeForLocalAndExternalItems(): void
    {
        $this->attachMediaFile(1, 'clip.mp4', 'fake-mp4-bytes');
        $this->attachMediaFile(2, 'clip.youtube', 'dQw4w9WgXcQ');

        $records = [
            $this->mediaRecord(['uid' => 1, 'media_type' => 'video', 'title' => 'Local Clip']),
            $this->mediaRecord(['uid' => 2, 'media_type' => 'youtube', 'title' => 'YouTube Clip']),
        ];
        $result = $this->subject->assembleForMediaRecords($records, ['uid' => 10], $this->request(), 0);

        self::assertSame('mixedPlaylist', $result['renderMode']);
        self::assertTrue($result['isMixedPlaylist']);
        self::assertCount(2, $result['tracks']);
    }
}
