<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Streaming;

use Mpc\MpcVidply\Dto\StreamSource;
use Mpc\MpcVidply\Enums\StreamFormat;

/**
 * Picks the local video files of a record that the conversion processes:
 * the first progressive main video, every progressive audio-description
 * video and every progressive sign-language video.
 */
final readonly class StreamSourceCollector
{
    /** Containers ffmpeg reads that editors can upload as a video file. */
    public const CONVERTIBLE_EXTENSIONS = ['mp4', 'm4v', 'mov', 'webm', 'mkv'];

    public function __construct(
        private StreamRecordRepository $repository,
        private StreamingSettings $settings,
    ) {}

    /**
     * @return list<StreamSource> Main video first (when there is one)
     */
    public function collect(int $recordUid): array
    {
        $sources = [];
        foreach ($this->convertibleReferences($recordUid, 'media_file') as $reference) {
            $sources[] = $this->toSource(StreamSource::ROLE_MAIN, 'media_file', $reference);
            break;
        }
        foreach ($this->convertibleReferences($recordUid, 'audio_description') as $reference) {
            $sources[] = $this->toSource(StreamSource::ROLE_AUDIO_DESCRIPTION, 'audio_description', $reference);
        }
        foreach ($this->convertibleReferences($recordUid, 'sign_language') as $reference) {
            $sources[] = $this->toSource(StreamSource::ROLE_SIGN_LANGUAGE, 'sign_language', $reference);
        }

        return $sources;
    }

    /**
     * @param list<StreamSource> $sources
     */
    public static function findMain(array $sources): ?StreamSource
    {
        foreach ($sources as $source) {
            if ($source->role === StreamSource::ROLE_MAIN) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Fingerprint of everything the output depends on: format, source files
     * (by content) and rendition settings. A record is queued again when it
     * changes, and not when an editor only edits the title.
     *
     * @param list<StreamSource> $sources
     */
    public function signature(StreamFormat $format, array $sources): string
    {
        $configuration = $this->settings->get();
        $parts = [
            'format' => $format->value,
            'renditions' => $configuration->renditions,
            'signRenditions' => $configuration->signLanguageRenditions,
            'segment' => $configuration->segmentDuration,
            'sources' => array_map(
                static fn (StreamSource $source): array => [$source->role, $source->fileUid, $source->sha1],
                $sources
            ),
        ];

        return sha1((string)json_encode($parts));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function convertibleReferences(int $recordUid, string $fieldName): array
    {
        return array_values(array_filter(
            $this->repository->findReferences(StreamRecordRepository::MEDIA_TABLE, $recordUid, $fieldName),
            static fn (array $reference): bool => (int)($reference['file_storage'] ?? 0) > 0
                && (int)($reference['file_missing'] ?? 0) === 0
                && (int)($reference['hidden'] ?? 0) === 0
                && in_array(strtolower((string)($reference['file_extension'] ?? '')), self::CONVERTIBLE_EXTENSIONS, true)
        ));
    }

    /**
     * @param array<string, mixed> $reference
     */
    private function toSource(string $role, string $fieldName, array $reference): StreamSource
    {
        return new StreamSource(
            role: $role,
            fieldName: $fieldName,
            referenceUid: (int)$reference['uid'],
            fileUid: (int)$reference['uid_local'],
            fileName: (string)($reference['file_name'] ?? ''),
            sha1: (string)($reference['file_sha1'] ?? ''),
        );
    }
}
