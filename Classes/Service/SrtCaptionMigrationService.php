<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use Mpc\MpcVidply\Dto\FileConversionResult;
use Mpc\MpcVidply\Dto\MigrationBatchResult;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\MimeTypeDetector;
use TYPO3\CMS\Core\Resource\ResourceFactory;

final class SrtCaptionMigrationService
{
    private const MEDIA_TABLE = 'tx_mpcvidply_media';

    /** @var list<string> */
    private const MEDIA_TRACK_FIELDS = ['captions', 'chapters'];

    public function __construct(
        private readonly SrtToVttConverter $converter,
        private readonly ResourceFactory $resourceFactory,
        private readonly FileRepository $fileRepository,
        private readonly ConnectionPool $connectionPool,
        private readonly MimeTypeDetector $mimeTypeDetector,
    ) {}

    /**
     * @return list<array{
     *     file_reference_uid: int,
     *     file_name: string,
     *     tablenames: string,
     *     fieldname: string,
     *     media_uid: int,
     *     media_title: string
     * }>
     */
    public function findAllSrtReferences(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');

        $rows = $queryBuilder
            ->select(
                'sfr.uid AS file_reference_uid',
                'sf.name AS file_name',
                'sfr.tablenames',
                'sfr.fieldname',
                'sfr.uid_foreign',
                'media.uid AS media_uid',
                'media.title AS media_title',
            )
            ->from('sys_file_reference', 'sfr')
            ->innerJoin(
                'sfr',
                'sys_file',
                'sf',
                $queryBuilder->expr()->eq('sf.uid', $queryBuilder->quoteIdentifier('sfr.uid_local'))
            )
            ->leftJoin(
                'sfr',
                self::MEDIA_TABLE,
                'media',
                $queryBuilder->expr()->and(
                    $queryBuilder->expr()->eq('media.uid', $queryBuilder->quoteIdentifier('sfr.uid_foreign')),
                    $queryBuilder->expr()->eq('sfr.tablenames', $queryBuilder->createNamedParameter(self::MEDIA_TABLE))
                )
            )
            ->where(
                $queryBuilder->expr()->eq('sf.extension', $queryBuilder->createNamedParameter('srt')),
                $queryBuilder->expr()->eq('sfr.deleted', 0),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq('sfr.tablenames', $queryBuilder->createNamedParameter(self::MEDIA_TABLE)),
                        $queryBuilder->expr()->in(
                            'sfr.fieldname',
                            $queryBuilder->createNamedParameter(self::MEDIA_TRACK_FIELDS, Connection::PARAM_STR_ARRAY)
                        )
                    ),
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq('sfr.tablenames', $queryBuilder->createNamedParameter('sys_file_reference')),
                        $queryBuilder->expr()->eq('sfr.fieldname', $queryBuilder->createNamedParameter('tx_desc_src_file'))
                    )
                )
            )
            ->orderBy('sfr.uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $results = [];
        foreach ($rows as $row) {
            $fileReferenceUid = (int)($row['file_reference_uid'] ?? 0);
            if ($fileReferenceUid <= 0) {
                continue;
            }

            $mediaUid = (int)($row['media_uid'] ?? 0);
            if ($mediaUid <= 0 && ($row['tablenames'] ?? '') === 'sys_file_reference') {
                $mediaUid = $this->resolveMediaUidForDescribedSource($fileReferenceUid);
            }

            $results[] = [
                'file_reference_uid' => $fileReferenceUid,
                'file_name' => (string)($row['file_name'] ?? ''),
                'tablenames' => (string)($row['tablenames'] ?? ''),
                'fieldname' => (string)($row['fieldname'] ?? ''),
                'media_uid' => $mediaUid,
                'media_title' => (string)($row['media_title'] ?? ''),
            ];
        }

        return $results;
    }

    public function migrateAll(bool $dryRun = false, ?int $limit = null): MigrationBatchResult
    {
        $references = $this->findAllSrtReferences();
        if ($limit !== null && $limit > 0) {
            $references = array_slice($references, 0, $limit);
        }

        $results = [];
        foreach ($references as $reference) {
            $results[] = $this->convertFileReferenceByUid($reference['file_reference_uid'], $dryRun);
        }

        return new MigrationBatchResult($results);
    }

    /**
     * @param int[] $mediaUids
     */
    public function convertForMediaRecords(array $mediaUids, bool $dryRun = false): MigrationBatchResult
    {
        $mediaUids = array_values(array_filter(array_map('intval', $mediaUids), static fn (int $uid): bool => $uid > 0));
        if ($mediaUids === []) {
            return new MigrationBatchResult([]);
        }

        $processedFileReferenceUids = [];
        $results = [];

        foreach ($mediaUids as $mediaUid) {
            foreach (self::MEDIA_TRACK_FIELDS as $fieldName) {
                foreach ($this->fileRepository->findByRelation(self::MEDIA_TABLE, $fieldName, $mediaUid) as $fileReference) {
                    $referenceUids = [$fileReference->getUid()];
                    $describedSourceUid = $this->findDescribedSourceReferenceUid($fileReference->getUid());
                    if ($describedSourceUid > 0) {
                        $referenceUids[] = $describedSourceUid;
                    }

                    foreach ($referenceUids as $referenceUid) {
                        if (isset($processedFileReferenceUids[$referenceUid])) {
                            continue;
                        }
                        $processedFileReferenceUids[$referenceUid] = true;
                        $results[] = $this->convertFileReferenceByUid($referenceUid, $dryRun);
                    }
                }
            }
        }

        return new MigrationBatchResult($results);
    }

    public function convertFileReferenceByUid(int $fileReferenceUid, bool $dryRun = false): FileConversionResult
    {
        if ($fileReferenceUid <= 0) {
            return FileConversionResult::skipped('', 'Invalid file reference.');
        }

        try {
            $fileReference = $this->resourceFactory->getFileReferenceObject($fileReferenceUid);
        } catch (ResourceDoesNotExistException) {
            return FileConversionResult::skipped('', 'File reference no longer exists.');
        }

        return $this->convertFileReference($fileReference, $dryRun);
    }

    public function convertFileReference(FileReference $fileReference, bool $dryRun = false): FileConversionResult
    {
        $originalFile = $fileReference->getOriginalFile();

        $originalName = $originalFile->getName();
        if ($originalFile->getExtension() !== 'srt') {
            return FileConversionResult::skipped($originalName, 'File is not an SRT subtitle.');
        }

        if ($dryRun) {
            $conversion = $this->converter->convert($originalFile->getContents());
            if (!$conversion->success) {
                return FileConversionResult::failed($originalName, $conversion->errorMessage);
            }

            return FileConversionResult::converted(
                $originalName,
                $this->buildVttFileName($originalName)
            );
        }

        $conversion = $this->converter->convert($originalFile->getContents());
        if (!$conversion->success) {
            return FileConversionResult::failed($originalName, $conversion->errorMessage);
        }

        try {
            $newName = $this->replaceFileWithVtt($originalFile, $conversion->vtt);
        } catch (\Throwable $throwable) {
            return FileConversionResult::failed($originalName, $throwable->getMessage());
        }

        return FileConversionResult::converted($originalName, $newName);
    }

    private function findDescribedSourceReferenceUid(int $parentFileReferenceUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $uid = $queryBuilder
            ->select('uid')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('sys_file_reference')),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter('tx_desc_src_file')),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($parentFileReferenceUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($uid) ? (int)$uid : 0;
    }

    private function resolveMediaUidForDescribedSource(int $describedSourceReferenceUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $parentReferenceUid = $queryBuilder
            ->select('uid_foreign')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($describedSourceReferenceUid, Connection::PARAM_INT))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if (!is_numeric($parentReferenceUid)) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $mediaUid = $queryBuilder
            ->select('uid_foreign')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter((int)$parentReferenceUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter(self::MEDIA_TABLE)),
                $queryBuilder->expr()->in(
                    'fieldname',
                    $queryBuilder->createNamedParameter(self::MEDIA_TRACK_FIELDS, Connection::PARAM_STR_ARRAY)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($mediaUid) ? (int)$mediaUid : 0;
    }

    private function replaceFileWithVtt(File $file, string $vttContent): string
    {
        $file->setContents($vttContent);
        $targetName = $this->buildVttFileName($file->getName());
        if ($file->getName() === $targetName) {
            return $targetName;
        }

        $this->alignMimeTypeWithExtension($file, $targetName);

        $renamedFile = $file->rename($targetName, DuplicationBehavior::RENAME);
        return $renamedFile->getName();
    }

    /**
     * The file still carries the MIME type detected for the former ".srt" file
     * (e.g. "application/x-subrip"). TYPO3's resource consistency check rejects
     * renaming it to ".vtt" unless the MIME type matches the target extension.
     * Since the contents are now valid WebVTT, align the MIME type with the new
     * extension before renaming. After the rename TYPO3 re-indexes the file, so
     * the persisted MIME type is refreshed from the actual contents anyway.
     */
    private function alignMimeTypeWithExtension(File $file, string $targetFileName): void
    {
        $extension = strtolower(pathinfo($targetFileName, PATHINFO_EXTENSION));
        if ($extension === '') {
            return;
        }

        $expectedMimeTypes = $this->mimeTypeDetector->getMimeTypesForFileExtension($extension);
        if ($expectedMimeTypes === [] || in_array($file->getMimeType(), $expectedMimeTypes, true)) {
            return;
        }

        $file->updateProperties(['mime_type' => (string)reset($expectedMimeTypes)]);
    }

    /**
     * @return non-empty-string
     */
    private function buildVttFileName(string $originalName): string
    {
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        if ($baseName === '') {
            $baseName = 'captions';
        }

        return $baseName . '.vtt';
    }
}
