<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Streaming;

use Mpc\MpcVidply\Dto\StreamConversionResult;
use Mpc\MpcVidply\Dto\StreamingConfiguration;
use Mpc\MpcVidply\Dto\StreamSource;
use Mpc\MpcVidply\Enums\StreamFormat;
use Mpc\MpcVidply\Enums\StreamStatus;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Converts the queued video of one media record and publishes the result as
 * its streaming variant.
 *
 * Output layout below the target folder:
 *
 *     <video-slug>-<uid>/            main manifests, segments, generated poster
 *         ad-<file-slug>/            one folder per audio-description video
 *         sign-<file-slug>/          one folder per sign-language video + MP4 fallback
 */
final class StreamConversionService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const MAX_ERROR_LENGTH = 2000;

    public function __construct(
        private readonly StreamRecordRepository $repository,
        private readonly StreamSourceCollector $sourceCollector,
        private readonly StreamingSettings $settings,
        private readonly FfmpegVersionChecker $versionChecker,
        private readonly FfprobeReader $probeReader,
        private readonly FfmpegCommandBuilder $commandBuilder,
        private readonly StreamNaming $naming,
        private readonly ProcessRunnerInterface $processRunner,
        private readonly StreamOutputStorage $outputStorage,
        private readonly StreamVariantRecordService $variantService,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    public function convert(int $recordUid): StreamConversionResult
    {
        $record = $this->repository->findRecord($recordUid);
        if ($record === null
            || (int)$record['sys_language_uid'] !== 0
            || ($record['media_type'] ?? '') !== 'video'
            || (int)($record['stream_source'] ?? 0) > 0
        ) {
            return StreamConversionResult::skipped($recordUid, 'Not a default-language video record.');
        }
        if ((int)($record['stream_convert'] ?? 0) !== 1) {
            return StreamConversionResult::skipped($recordUid, 'Streaming conversion is not enabled for this record.');
        }

        $ffmpeg = $this->versionChecker->check();
        if (!$ffmpeg->isUsable()) {
            $message = $ffmpeg->isTooOld()
                ? sprintf('ffmpeg %s is too old; version %s or newer is required.', (string)$ffmpeg->ffmpegVersion, $ffmpeg->minimumVersion)
                : sprintf('ffmpeg/ffprobe not found or not executable (%s, %s). %s', $ffmpeg->ffmpegPath, $ffmpeg->ffprobePath, $ffmpeg->error);

            return $this->fail($recordUid, $message);
        }

        $format = StreamFormat::fromRecordValue($record['stream_format'] ?? '');
        $sources = $this->sourceCollector->collect($recordUid);
        $main = StreamSourceCollector::findMain($sources);
        if ($main === null) {
            return $this->fail($recordUid, 'The record has no local video file (MP4, WebM, MOV, MKV) to convert.');
        }
        $signature = $this->sourceCollector->signature($format, $sources);

        $this->repository->updateRecord($recordUid, ['stream_status' => StreamStatus::Processing->value, 'stream_error' => '']);
        $workDirectory = $this->createWorkDirectory($recordUid);

        try {
            $configuration = $this->settings->get();
            $hasPoster = $this->repository->findReferences(StreamRecordRepository::MEDIA_TABLE, $recordUid, 'poster') !== [];
            $plan = $this->encodeAll($configuration, $format, $sources, $workDirectory, !$hasPoster);

            $published = $this->outputStorage->publish(
                $workDirectory,
                $this->naming->outputFolderName($main->fileName, $recordUid),
                $plan['index']
            );
            $fileUid = static function (string $relativePath) use ($published): int {
                $file = $published['files'][$relativePath] ?? null;
                if ($file === null) {
                    throw new \RuntimeException(sprintf('"%s" was not written.', $relativePath), 1762156831);
                }
                return $file->getUid();
            };

            $mapping = [];
            foreach ($plan['mapping'] as $field => $entries) {
                foreach ($entries as $sourceFileUid => $entry) {
                    $mapping[$field][$sourceFileUid] = [
                        'replacements' => array_map($fileUid, $entry['paths']),
                        'keepOriginal' => $entry['keepOriginal'],
                    ];
                }
            }
            $posterUid = $plan['poster'] !== '' ? $fileUid($plan['poster']) : 0;

            $variantUid = $this->variantService->createOrUpdate($record, $mapping, $posterUid);
            $ffmpegVersion = (string)($ffmpeg->ffmpegVersion ?? 'unknown');
            $now = time();
            $this->repository->updateRecord($variantUid, [
                'stream_format' => $format->value,
                'stream_converted_at' => $now,
                'stream_ffmpeg_version' => $ffmpegVersion,
            ]);

            $previousFolder = (string)($record['stream_output_folder'] ?? '');
            if ($previousFolder !== '' && $previousFolder !== $published['folder']) {
                $this->outputStorage->delete($previousFolder);
            }

            // An editor may have replaced a file or switched the format meanwhile.
            $current = $this->repository->findRecord($recordUid);
            $currentSignature = $current === null ? $signature : $this->sourceCollector->signature(
                StreamFormat::fromRecordValue($current['stream_format'] ?? ''),
                $this->sourceCollector->collect($recordUid)
            );
            $requeue = $currentSignature !== $signature && (int)($current['stream_convert'] ?? 0) === 1;

            $this->repository->updateRecord($recordUid, [
                'stream_status' => $requeue ? StreamStatus::Pending->value : StreamStatus::Done->value,
                'stream_error' => '',
                'stream_converted_at' => $now,
                'stream_ffmpeg_version' => $ffmpegVersion,
                'stream_record' => $variantUid,
                'stream_signature' => $requeue ? '' : $signature,
                'stream_output_folder' => $published['folder'],
            ]);

            return $requeue
                ? StreamConversionResult::requeued($recordUid, $variantUid)
                : StreamConversionResult::converted($recordUid, $variantUid);
        } catch (\Throwable $exception) {
            $this->logger?->error('Streaming conversion of media record {uid} failed: {message}', [
                'uid' => $recordUid,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return $this->fail($recordUid, $exception->getMessage());
        } finally {
            GeneralUtility::rmdir($workDirectory, true);
        }
    }

    /**
     * Run every ffmpeg job into the work directory.
     *
     * @param list<StreamSource> $sources
     * @return array{
     *     index: list<string>,
     *     poster: string,
     *     mapping: array<string, array<int, array{paths: list<string>, keepOriginal: bool}>>
     * }
     */
    private function encodeAll(
        StreamingConfiguration $configuration,
        StreamFormat $format,
        array $sources,
        string $workDirectory,
        bool $generatePoster,
    ): array {
        $index = [];
        $mapping = [];
        $poster = '';
        $usedFolders = [];

        foreach ($sources as $source) {
            if (isset($mapping[$source->fieldName][$source->fileUid])) {
                continue;
            }
            $localPath = $this->localPath($source->fileUid);
            $probe = $this->probeReader->probe($localPath);
            $baseName = $this->naming->baseName($source->fileName);

            $subFolder = '';
            if ($source->role !== StreamSource::ROLE_MAIN) {
                $prefix = $source->role === StreamSource::ROLE_SIGN_LANGUAGE ? 'sign' : 'ad';
                $subFolder = $this->naming->accessibilityFolderName($prefix, $source->fileName);
                $candidate = $subFolder;
                for ($suffix = 2; isset($usedFolders[$candidate]); $suffix++) {
                    $candidate = $subFolder . '-' . $suffix;
                }
                $subFolder = $candidate;
                $usedFolders[$subFolder] = true;
            }
            $outputDirectory = $workDirectory . ($subFolder !== '' ? '/' . $subFolder : '');
            GeneralUtility::mkdir_deep($outputDirectory);
            $prefixPath = $subFolder !== '' ? $subFolder . '/' : '';

            $isSignLanguage = $source->role === StreamSource::ROLE_SIGN_LANGUAGE;
            $heights = FfmpegCommandBuilder::selectHeights(
                $isSignLanguage ? $configuration->signLanguageRenditions : $configuration->renditions,
                $probe->height
            );

            $this->runFfmpeg($this->commandBuilder->buildStreamCommand(
                $configuration,
                $format,
                $localPath,
                $outputDirectory,
                $baseName,
                $heights,
                $probe->hasAudio && !$isSignLanguage,
            ), $source);

            $paths = [];
            if ($format->producesDash()) {
                $paths[] = $prefixPath . FfmpegCommandBuilder::dashManifestName($baseName);
            }
            if ($format->producesHls()) {
                $paths[] = $prefixPath . FfmpegCommandBuilder::hlsManifestName($baseName);
            }

            if ($isSignLanguage) {
                $fallback = $prefixPath . $baseName . '-' . $heights[0] . 'p.mp4';
                $this->runFfmpeg($this->commandBuilder->buildMp4FallbackCommand(
                    $configuration,
                    $localPath,
                    $workDirectory . '/' . $fallback,
                    $heights[0],
                ), $source);
                $paths[] = $fallback;
            }

            if ($source->role === StreamSource::ROLE_MAIN && $generatePoster) {
                $poster = $baseName . '-poster.jpg';
                $this->runFfmpeg($this->commandBuilder->buildPosterCommand(
                    $configuration,
                    $localPath,
                    $workDirectory . '/' . $poster,
                    $probe->duration * 0.1,
                ), $source);
                $index[] = $poster;
            }

            foreach ($paths as $path) {
                if (!is_file($workDirectory . '/' . $path)) {
                    throw new \RuntimeException(sprintf('ffmpeg finished without writing "%s".', $path), 1762156832);
                }
            }
            $index = [...$index, ...$paths];
            $mapping[$source->fieldName][$source->fileUid] = [
                'paths' => $paths,
                // Downloads offer the original MP4; audio description falls back to it.
                'keepOriginal' => !$isSignLanguage,
            ];
        }

        return ['index' => $index, 'poster' => $poster, 'mapping' => $mapping];
    }

    /**
     * @param list<string> $command
     */
    private function runFfmpeg(array $command, StreamSource $source): void
    {
        $result = $this->processRunner->run($command);
        if (!$result->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'ffmpeg failed for "%s" (exit code %d): %s',
                $source->fileName,
                $result->exitCode,
                $result->errorTail()
            ), 1762156833);
        }
    }

    private function localPath(int $fileUid): string
    {
        $file = $this->resourceFactory->getFileObject($fileUid);
        $path = $file->getForLocalProcessing(false);
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('The file "%s" is missing on disk.', $file->getName()), 1762156834);
        }

        return $path;
    }

    private function createWorkDirectory(int $recordUid): string
    {
        $directory = Environment::getVarPath() . '/transient/mpc_vidply_stream/' . $recordUid . '-' . bin2hex(random_bytes(4));
        GeneralUtility::mkdir_deep($directory);

        return $directory;
    }

    private function fail(int $recordUid, string $message): StreamConversionResult
    {
        $message = mb_substr(trim($message), 0, self::MAX_ERROR_LENGTH);
        $this->repository->updateRecord($recordUid, [
            'stream_status' => StreamStatus::Failed->value,
            'stream_error' => $message,
        ]);

        return StreamConversionResult::failed($recordUid, $message);
    }
}
