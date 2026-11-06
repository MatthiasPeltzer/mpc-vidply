<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Streaming;

use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates and refreshes the streaming variant of a media record.
 *
 * The variant is a DataHandler copy of the original (same page, all
 * translations) titled "<title> (Stream)". Its file fields mirror the
 * original's, with each converted source file replaced by its manifests.
 * A re-run updates the variant in place so content elements that reference
 * it keep working.
 */
final readonly class StreamVariantRecordService
{
    public const TITLE_SUFFIX = ' (Stream)';

    private const TABLE = StreamRecordRepository::MEDIA_TABLE;

    /** File fields mirrored from the original. */
    private const FILE_FIELDS = ['media_file', 'poster', 'captions', 'chapters', 'audio_description', 'sign_language'];

    /** Plain fields mirrored from the original; visibility stays the editor's. */
    private const MIRRORED_FIELDS = [
        'artist',
        'description',
        'long_description',
        'duration',
        'audio_description_duration',
        'audio_description_mode',
        'publish_date',
        'episode_number',
        'sign_language_display_mode',
        'enable_transcript',
        'hide_speed_button',
        'hide_help_button',
        'allow_download',
        'enable_floating_player',
    ];

    public function __construct(
        private StreamRecordRepository $repository,
    ) {}

    /**
     * @param array<string, mixed> $original Default-language media record
     * @param array<string, array<int, array{replacements: list<int>, keepOriginal: bool}>> $fileMapping
     *        Field name => source sys_file uid => files that take its place
     * @param int $generatedPosterFileUid Poster used when the original has none (0: none)
     * @return int Uid of the variant record
     * @throws \RuntimeException
     */
    public function createOrUpdate(array $original, array $fileMapping, int $generatedPosterFileUid): int
    {
        $originalUid = (int)$original['uid'];
        $variantUid = $this->findExistingVariantUid($original) ?? $this->copyRecord($originalUid);

        $originalTranslations = $this->repository->findTranslations($originalUid);
        $this->localizeMissingTranslations($variantUid, $originalTranslations);
        $variantTranslations = $this->repository->findTranslations($variantUid);

        $this->updateTitles($original, $variantUid, $originalTranslations, $variantTranslations);

        $variant = $this->repository->findRecord($variantUid)
            ?? throw new \RuntimeException('The streaming variant disappeared while it was updated.', 1762156821);
        $referenceMap = [];
        $this->mirrorRecord($original, $variant, $fileMapping, $generatedPosterFileUid, $referenceMap);
        foreach ($variantTranslations as $languageUid => $variantTranslation) {
            if (isset($originalTranslations[$languageUid])) {
                $this->mirrorRecord($originalTranslations[$languageUid], $variantTranslation, $fileMapping, $generatedPosterFileUid, $referenceMap);
            }
        }

        $this->repository->updateRecord($variantUid, ['stream_source' => $originalUid]);

        $referenceIndex = GeneralUtility::makeInstance(ReferenceIndex::class);
        $referenceIndex->updateRefIndexTable(self::TABLE, $variantUid);
        foreach ($variantTranslations as $variantTranslation) {
            $referenceIndex->updateRefIndexTable(self::TABLE, (int)$variantTranslation['uid']);
        }

        return $variantUid;
    }

    /**
     * Delete a variant with DataHandler (translations and references follow).
     */
    public function delete(int $variantUid): void
    {
        $variant = $this->repository->findRecord($variantUid);
        if ($variant === null || (int)($variant['stream_source'] ?? 0) === 0) {
            return;
        }
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [self::TABLE => [$variantUid => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();
    }

    public static function variantTitle(string $title): string
    {
        $title = trim($title);

        return str_ends_with($title, trim(self::TITLE_SUFFIX)) ? $title : $title . self::TITLE_SUFFIX;
    }

    /**
     * @param array<string, mixed> $original
     */
    private function findExistingVariantUid(array $original): ?int
    {
        $variant = $this->repository->findRecord((int)($original['stream_record'] ?? 0));
        if ($variant === null || (int)($variant['stream_source'] ?? 0) !== (int)$original['uid']) {
            return null;
        }

        return (int)$variant['uid'];
    }

    /**
     * @throws \RuntimeException
     */
    private function copyRecord(int $originalUid): int
    {
        $original = $this->repository->findRecord($originalUid)
            ?? throw new \RuntimeException('The original record no longer exists.', 1762156822);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [self::TABLE => [$originalUid => ['copy' => (int)$original['pid']]]]);
        $dataHandler->process_cmdmap();

        $variantUid = (int)($dataHandler->copyMappingArray_merged[self::TABLE][$originalUid] ?? 0);
        if ($variantUid <= 0) {
            throw new \RuntimeException('Could not copy the record: ' . implode(' ', $dataHandler->errorLog), 1762156823);
        }
        // Mark it right away, so a failure later on still leaves a recognisable variant.
        $this->repository->updateRecord($variantUid, ['stream_source' => $originalUid]);

        return $variantUid;
    }

    /**
     * @param array<int, array<string, mixed>> $originalTranslations
     */
    private function localizeMissingTranslations(int $variantUid, array $originalTranslations): void
    {
        $existing = $this->repository->findTranslations($variantUid);
        foreach (array_keys($originalTranslations) as $languageUid) {
            if (isset($existing[$languageUid])) {
                continue;
            }
            // One command per language: a cmdmap holds a single command per record.
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], [self::TABLE => [$variantUid => ['localize' => $languageUid]]]);
            $dataHandler->process_cmdmap();
        }
    }

    /**
     * Titles and slugs through DataHandler, so the slug is regenerated from
     * the new title and made unique in the site.
     *
     * @param array<string, mixed> $original
     * @param array<int, array<string, mixed>> $originalTranslations
     * @param array<int, array<string, mixed>> $variantTranslations
     */
    private function updateTitles(array $original, int $variantUid, array $originalTranslations, array $variantTranslations): void
    {
        $data = [self::TABLE => [
            $variantUid => ['title' => self::variantTitle((string)$original['title']), 'slug' => ''],
        ]];
        foreach ($variantTranslations as $languageUid => $variantTranslation) {
            $title = (string)($originalTranslations[$languageUid]['title'] ?? $original['title']);
            $data[self::TABLE][(int)$variantTranslation['uid']] = ['title' => self::variantTitle($title), 'slug' => ''];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();
        if ($dataHandler->errorLog !== []) {
            throw new \RuntimeException('Could not update the variant title: ' . implode(' ', $dataHandler->errorLog), 1762156824);
        }
    }

    /**
     * Copy plain fields and rebuild every file field of one language record.
     *
     * @param array<string, mixed> $source Original record (default or translation)
     * @param array<string, mixed> $target Matching variant record
     * @param array<string, array<int, array{replacements: list<int>, keepOriginal: bool}>> $fileMapping
     * @param array<int, list<int>> $referenceMap Original default-language reference uid => new variant reference uids
     */
    private function mirrorRecord(array $source, array $target, array $fileMapping, int $generatedPosterFileUid, array &$referenceMap): void
    {
        $fields = [];
        foreach (self::MIRRORED_FIELDS as $field) {
            if (array_key_exists($field, $source)) {
                $value = $source[$field];
                $fields[$field] = is_int($value) || is_string($value) || $value === null ? $value : '';
            }
        }
        foreach (self::FILE_FIELDS as $field) {
            $fields[$field] = $this->mirrorReferences($source, $target, $field, $fileMapping[$field] ?? [], $generatedPosterFileUid, $referenceMap);
        }

        $this->repository->updateRecord((int)$target['uid'], $fields);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     * @param array<int, array{replacements: list<int>, keepOriginal: bool}> $mapping
     * @param array<int, list<int>> $referenceMap
     * @return int Number of references now on the field
     */
    private function mirrorReferences(array $source, array $target, string $field, array $mapping, int $generatedPosterFileUid, array &$referenceMap): int
    {
        $targetUid = (int)$target['uid'];
        $isTranslation = (int)($target['sys_language_uid'] ?? 0) > 0;
        $columns = array_flip($this->repository->referenceColumns());
        $now = time();

        $this->repository->deleteReferences(self::TABLE, $targetUid, $field);
        $references = $this->repository->findReferences(self::TABLE, (int)$source['uid'], $field);

        $sorting = 0;
        foreach ($references as $reference) {
            $sourceFileUid = (int)$reference['uid_local'];
            $entry = $mapping[$sourceFileUid] ?? null;
            $fileUids = $entry === null
                ? [$sourceFileUid]
                : [...$entry['replacements'], ...($entry['keepOriginal'] ? [$sourceFileUid] : [])];
            $parentReferences = $referenceMap[(int)($reference['l10n_parent'] ?? 0)] ?? [];

            foreach ($fileUids as $position => $fileUid) {
                $row = array_intersect_key($reference, $columns);
                unset($row['uid']);
                $row = array_replace($row, [
                    'pid' => (int)$target['pid'],
                    'tstamp' => $now,
                    'crdate' => $now,
                    'uid_local' => $fileUid,
                    'uid_foreign' => $targetUid,
                    'sorting_foreign' => ++$sorting,
                    'sys_language_uid' => (int)($target['sys_language_uid'] ?? 0),
                    'l10n_parent' => $isTranslation ? ($parentReferences[$position] ?? 0) : 0,
                    'l10n_diffsource' => '',
                ]);
                $newUid = $this->repository->insertReference(array_intersect_key($row, $columns));
                if (!$isTranslation) {
                    $referenceMap[(int)$reference['uid']][] = $newUid;
                }
                if ((int)($reference['tx_desc_src_file'] ?? 0) > 0) {
                    $this->copyNestedReferences((int)$reference['uid'], $newUid, (int)$target['pid'], $columns);
                }
            }
        }

        if ($field === 'poster' && $references === [] && $generatedPosterFileUid > 0) {
            $parentReferences = $referenceMap[0 - $generatedPosterFileUid] ?? [];
            $newUid = $this->repository->insertReference(array_intersect_key([
                'pid' => (int)$target['pid'],
                'tstamp' => $now,
                'crdate' => $now,
                'uid_local' => $generatedPosterFileUid,
                'uid_foreign' => $targetUid,
                'tablenames' => self::TABLE,
                'fieldname' => 'poster',
                'sorting_foreign' => ++$sorting,
                'sys_language_uid' => (int)($target['sys_language_uid'] ?? 0),
                'l10n_parent' => $isTranslation ? ($parentReferences[0] ?? 0) : 0,
                'alternative' => trim((string)($source['title'] ?? '')),
            ], $columns));
            if (!$isTranslation) {
                $referenceMap[0 - $generatedPosterFileUid] = [$newUid];
            }
        }

        return $sorting;
    }

    /**
     * Described-VTT alternatives hang off caption/chapter references.
     *
     * @param array<string, int> $columns
     */
    private function copyNestedReferences(int $sourceReferenceUid, int $targetReferenceUid, int $pid, array $columns): void
    {
        $now = time();
        foreach ($this->repository->findReferences(StreamRecordRepository::REFERENCE_TABLE, $sourceReferenceUid, 'tx_desc_src_file') as $nested) {
            $row = array_intersect_key($nested, $columns);
            unset($row['uid']);
            $this->repository->insertReference(array_intersect_key(array_replace($row, [
                'pid' => $pid,
                'tstamp' => $now,
                'crdate' => $now,
                'uid_foreign' => $targetReferenceUid,
                'l10n_parent' => 0,
                'l10n_diffsource' => '',
            ]), $columns));
        }
    }
}
