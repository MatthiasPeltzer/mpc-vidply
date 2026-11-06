<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\Service\Streaming;

use Mpc\MpcVidply\Dto\ProcessResult;
use Mpc\MpcVidply\Dto\StreamConversionResult;
use Mpc\MpcVidply\Enums\StreamFormat;
use Mpc\MpcVidply\Enums\StreamStatus;
use Mpc\MpcVidply\Service\Streaming\FfmpegCommandBuilder;
use Mpc\MpcVidply\Service\Streaming\FfmpegVersionChecker;
use Mpc\MpcVidply\Service\Streaming\FfprobeReader;
use Mpc\MpcVidply\Service\Streaming\ProcessRunnerInterface;
use Mpc\MpcVidply\Service\Streaming\StreamConversionService;
use Mpc\MpcVidply\Service\Streaming\StreamingSettings;
use Mpc\MpcVidply\Service\Streaming\StreamNaming;
use Mpc\MpcVidply\Service\Streaming\StreamOutputStorage;
use Mpc\MpcVidply\Service\Streaming\StreamRecordRepository;
use Mpc\MpcVidply\Service\Streaming\StreamSourceCollector;
use Mpc\MpcVidply\Service\Streaming\StreamVariantRecordService;
use Mpc\MpcVidply\Tests\Functional\MpcVidplyFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class StreamConversionServiceTest extends MpcVidplyFunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mpc/mpc-vidply'];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'mpc_vidply' => [
                'streamingEnabled' => '1',
                'streamingTargetFolder' => '1:/streams/',
                'streamingRenditions' => '1080,720,480',
                'streamingSignLanguageRenditions' => '480,360',
            ],
        ],
    ];

    private const TABLE = 'tx_mpcvidply_media';

    private ResourceStorage $storage;

    private File $video;

    private File $captions;

    /** @var \ArrayObject<int, list<string>> ffmpeg/ffprobe calls of the fake runner */
    private \ArrayObject $commands;

    protected function setUp(): void
    {
        parent::setUp();
        $this->commands = new \ArrayObject();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/BackendAdmin.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/MediaRecords.csv');
        $this->setUpBackendUser(1);
        $this->setUpSite();

        // The test instance and its fileadmin/ are shared by all tests of this class.
        GeneralUtility::rmdir(Environment::getPublicPath() . '/fileadmin', true);
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin');
        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
        $storageId = $storageRepository->createLocalStorage('Test', 'fileadmin/', 'relative', 'Test storage', true);
        self::assertSame(1, $storageId);
        $storage = $storageRepository->findByUid($storageId);
        self::assertInstanceOf(ResourceStorage::class, $storage);
        $storage->setEvaluatePermissions(false);
        $this->storage = $storage;

        $this->video = $this->createFile('My Clip.mp4');
        $this->captions = $this->createFile('captions.vtt', "WEBVTT\n");
        $this->reference($this->video, 1, 'media_file');
        $this->reference($this->createFile('Clip AD.mp4'), 1, 'audio_description');
        $this->reference($this->createFile('DGS.mp4'), 1, 'sign_language');
        $this->reference($this->captions, 1, 'captions');
        $this->reference($this->captions, 11, 'captions');

        $this->updateRecord(1, [
            'stream_convert' => 1,
            'stream_format' => StreamFormat::Both->value,
            'stream_status' => StreamStatus::Pending->value,
            'allow_download' => 1,
            'artist' => 'Jane Doe',
        ]);
    }

    #[Test]
    public function convertingCreatesAStreamingVariantWithAllSources(): void
    {
        $result = $this->subject()->convert(1);

        self::assertSame(StreamConversionResult::STATUS_CONVERTED, $result->status, $result->message);
        $variantUid = $result->variantUid;

        $original = $this->record(1);
        self::assertSame(StreamStatus::Done->value, $original['stream_status']);
        self::assertSame($variantUid, (int)$original['stream_record']);
        self::assertSame('1:/streams/my-clip-1/', $original['stream_output_folder']);
        self::assertSame('8.0.1', $original['stream_ffmpeg_version']);

        $variant = $this->record($variantUid);
        self::assertSame('Clip One (Stream)', $variant['title']);
        self::assertSame(1, (int)$variant['stream_source']);
        self::assertSame(0, (int)$variant['stream_convert']);
        self::assertSame(1, (int)$variant['pid']);
        self::assertSame(1, (int)$variant['allow_download']);
        self::assertSame('Jane Doe', $variant['artist']);

        // FAL stores uploads as "My_Clip.mp4"; output names are slugs of that.
        self::assertSame(['my-clip.mpd', 'my-clip.m3u8', 'My_Clip.mp4'], $this->referencedNames($variantUid, 'media_file'));
        self::assertSame(['clip-ad.mpd', 'clip-ad.m3u8', 'Clip_AD.mp4'], $this->referencedNames($variantUid, 'audio_description'));
        self::assertSame(['dgs.mpd', 'dgs.m3u8', 'dgs-480p.mp4'], $this->referencedNames($variantUid, 'sign_language'));
        self::assertSame(['captions.vtt'], $this->referencedNames($variantUid, 'captions'));
        self::assertSame(['my-clip-poster.jpg'], $this->referencedNames($variantUid, 'poster'));

        $folder = Environment::getPublicPath() . '/fileadmin/streams/my-clip-1';
        self::assertFileExists($folder . '/my-clip.mpd');
        self::assertFileExists($folder . '/ad-clip-ad/clip-ad.m3u8');
        self::assertFileExists($folder . '/sign-dgs/dgs-480p.mp4');

        $translation = $this->translationOf($variantUid, 1);
        self::assertSame('Clip Eins (Stream)', $translation['title']);
        self::assertSame(['captions.vtt'], $this->referencedNames((int)$translation['uid'], 'captions'));
    }

    #[Test]
    public function signLanguageIsEncodedWithoutAudioAtItsOwnRenditions(): void
    {
        $this->subject()->convert(1);

        $signCommand = $this->commandWritingInto('/sign-dgs/');
        self::assertContains('-an', $signCommand);
        self::assertContains('[0:v]split=2[s0][s1];[s0]scale=-2:480[v0];[s1]scale=-2:360[v1]', $signCommand);

        $mainCommand = $this->commandWritingInto('/my-clip.mpd');
        self::assertContains('[0:v]split=2[s0][s1];[s0]scale=-2:720[v0];[s1]scale=-2:480[v1]', $mainCommand);
        self::assertNotContains('-an', $mainCommand);
    }

    #[Test]
    public function reconvertingUpdatesTheVariantInPlace(): void
    {
        $first = $this->subject()->convert(1);
        $manifestUid = $this->referencedUids($first->variantUid, 'media_file')[0];

        $this->updateRecord(1, ['stream_status' => StreamStatus::Pending->value]);
        $second = $this->subject()->convert(1);

        self::assertSame(StreamConversionResult::STATUS_CONVERTED, $second->status, $second->message);
        self::assertSame($first->variantUid, $second->variantUid);
        self::assertSame($manifestUid, $this->referencedUids($second->variantUid, 'media_file')[0]);
        self::assertCount(3, $this->referencedNames($second->variantUid, 'media_file'));
    }

    #[Test]
    public function anExistingPosterIsKeptAndNoneIsGenerated(): void
    {
        $poster = $this->createFile('poster.jpg');
        $this->reference($poster, 1, 'poster');

        $result = $this->subject()->convert(1);

        self::assertSame(['poster.jpg'], $this->referencedNames($result->variantUid, 'poster'));
        self::assertFileDoesNotExist(Environment::getPublicPath() . '/fileadmin/streams/my-clip-1/my-clip-poster.jpg');
    }

    #[Test]
    public function aFailingFfmpegRunMarksTheRecordFailed(): void
    {
        $result = $this->subject(failEncoding: true)->convert(1);

        self::assertSame(StreamConversionResult::STATUS_FAILED, $result->status);
        $original = $this->record(1);
        self::assertSame(StreamStatus::Failed->value, $original['stream_status']);
        self::assertStringContainsString('Unknown encoder', (string)$original['stream_error']);
        self::assertSame(0, (int)$original['stream_record']);
    }

    /**
     * DataHandler copies and localizes translations only inside a site that
     * has the language.
     */
    private function setUpSite(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages')
            ->update('pages', ['is_siteroot' => 1], ['uid' => 1]);

        $directory = Environment::getConfigPath() . '/sites/test';
        GeneralUtility::mkdir_deep($directory);
        file_put_contents($directory . '/config.yaml', Yaml::dump([
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
            ],
        ], 4));
    }

    private function subject(bool $failEncoding = false): StreamConversionService
    {
        $runner = new class ($this->commands, $failEncoding) implements ProcessRunnerInterface {
            /**
             * @param \ArrayObject<int, list<string>> $commands
             */
            public function __construct(private \ArrayObject $commands, private bool $failEncoding) {}

            public function run(array $command, ?float $timeout = null, ?string $workingDirectory = null): ProcessResult
            {
                $this->commands[] = $command;
                if (in_array('-version', $command, true)) {
                    return new ProcessResult(0, basename($command[0]) . " version 8.0.1-static https://johnvansickle.com\n", '');
                }
                if (in_array('-print_format', $command, true)) {
                    return new ProcessResult(0, (string)json_encode([
                        'streams' => [
                            ['codec_type' => 'video', 'width' => 1280, 'height' => 720],
                            ['codec_type' => 'audio'],
                        ],
                        'format' => ['duration' => '20.0'],
                    ]), '');
                }
                if ($this->failEncoding) {
                    return new ProcessResult(1, '', "Unknown encoder 'libx264'\n");
                }
                foreach ($this->writtenFiles($command) as $path) {
                    GeneralUtility::mkdir_deep(dirname($path));
                    file_put_contents($path, 'output');
                }

                return new ProcessResult(0, '', '');
            }

            /**
             * @param list<string> $command
             * @return list<string>
             */
            private function writtenFiles(array $command): array
            {
                $output = (string)end($command);
                $files = [$output];
                foreach (['-master_pl_name', '-hls_master_name'] as $option) {
                    $position = array_search($option, $command, true);
                    if (is_int($position)) {
                        $files[] = dirname($output) . '/' . $command[$position + 1];
                    }
                }

                return $files;
            }
        };

        $settings = $this->get(StreamingSettings::class);
        $repository = $this->get(StreamRecordRepository::class);

        return new StreamConversionService(
            repository: $repository,
            sourceCollector: $this->get(StreamSourceCollector::class),
            settings: $settings,
            versionChecker: new FfmpegVersionChecker($runner, $settings),
            probeReader: new FfprobeReader($runner, $settings),
            commandBuilder: $this->get(FfmpegCommandBuilder::class),
            naming: $this->get(StreamNaming::class),
            processRunner: $runner,
            outputStorage: $this->get(StreamOutputStorage::class),
            variantService: $this->get(StreamVariantRecordService::class),
            resourceFactory: $this->get(ResourceFactory::class),
        );
    }

    /**
     * @return list<string>
     */
    private function commandWritingInto(string $needle): array
    {
        foreach ($this->commands as $command) {
            if (!in_array('-print_format', $command, true) && str_contains((string)end($command), $needle)) {
                return $command;
            }
        }
        self::fail(sprintf('No ffmpeg run wrote into "%s".', $needle));
    }

    /**
     * @return list<string> File names in reference order
     */
    private function referencedNames(int $mediaUid, string $fieldName): array
    {
        return array_map(
            static fn (int $fileUid): string => GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($fileUid)->getName(),
            $this->referencedUids($mediaUid, $fieldName)
        );
    }

    /**
     * @return list<int> sys_file uids in reference order
     */
    private function referencedUids(int $mediaUid, string $fieldName): array
    {
        $references = $this->get(StreamRecordRepository::class)->findReferences(self::TABLE, $mediaUid, $fieldName);

        return array_map(static fn (array $reference): int => (int)$reference['uid_local'], $references);
    }

    /**
     * @return array<string, mixed>
     */
    private function translationOf(int $uid, int $languageUid): array
    {
        $translation = $this->get(StreamRecordRepository::class)->findTranslations($uid)[$languageUid] ?? null;
        self::assertIsArray($translation);

        return $translation;
    }

    /**
     * @return array<string, mixed>
     */
    private function record(int $uid): array
    {
        $record = $this->get(StreamRecordRepository::class)->findRecord($uid);
        self::assertIsArray($record);

        return $record;
    }

    /**
     * @param array<string, int|string> $fields
     */
    private function updateRecord(int $uid, array $fields): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, $fields, ['uid' => $uid]);
    }

    private function createFile(string $fileName, string $contents = 'video bytes'): File
    {
        $file = $this->storage->createFile($fileName, $this->storage->getRootLevelFolder());
        $file->setContents($contents . $fileName);

        return $file;
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
                'sys_language_uid' => $mediaUid === 11 ? 1 : 0,
            ]);
    }
}
