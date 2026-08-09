<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Player;

use Mpc\MpcVidply\Service\FileReferencePrefetcher;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The file side of one player assembly: every `sys_file_reference` of the media
 * records being rendered, plus the values that are expensive to ask a
 * FileReference for twice (public URL, MIME type, current size on disk).
 *
 * {@see prefetch()} starts a new assembly and drops what the previous one
 * cached, so the collaborators around it stay free of per-run state.
 */
final class MediaFileRegistry
{
    private const FOREIGN_TABLE = 'tx_mpcvidply_media';

    /** Fields whose references carry an alternative "described" VTT source. */
    private const DESCRIBED_SOURCE_FIELDS = ['captions', 'chapters'];

    private readonly FileRepository $fileRepository;
    private readonly ResourceFactory $resourceFactory;
    private readonly ConnectionPool $connectionPool;
    private readonly FileReferencePrefetcher $fileReferencePrefetcher;

    /** @var array<int, array<string, list<FileReference>>> */
    private array $referencesByMediaUid = [];

    /** @var array<int, string> */
    private array $publicUrlByReferenceUid = [];

    /** @var array<int, string> */
    private array $mimeTypeByReferenceUid = [];

    /** @var array<int, int> */
    private array $fileSizeByReferenceUid = [];

    /** @var array<int, FileReference> */
    private array $describedSourceByReferenceUid = [];

    /** @var array<string, string> */
    private array $inferredMimeTypeByUrl = [];

    /**
     * Parameters are optional so the registry can also be built through
     * GeneralUtility::makeInstance() from a DataProcessor.
     */
    public function __construct(
        ?FileRepository $fileRepository = null,
        ?ResourceFactory $resourceFactory = null,
        ?ConnectionPool $connectionPool = null,
        ?FileReferencePrefetcher $fileReferencePrefetcher = null
    ) {
        $this->fileRepository = $fileRepository ?? GeneralUtility::makeInstance(FileRepository::class);
        $this->resourceFactory = $resourceFactory ?? GeneralUtility::makeInstance(ResourceFactory::class);
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->fileReferencePrefetcher = $fileReferencePrefetcher
            ?? GeneralUtility::makeInstance(FileReferencePrefetcher::class);
    }

    /**
     * Load the references of every given media record in one query and discard
     * whatever a previous assembly left behind.
     *
     * @param list<int> $mediaUids
     * @param list<string> $fieldNames
     */
    public function prefetch(array $mediaUids, array $fieldNames): void
    {
        $this->referencesByMediaUid = [];
        $this->publicUrlByReferenceUid = [];
        $this->mimeTypeByReferenceUid = [];
        $this->fileSizeByReferenceUid = [];
        $this->describedSourceByReferenceUid = [];
        $this->inferredMimeTypeByUrl = [];

        $mediaUids = array_values(array_unique(array_filter($mediaUids, static fn (int $uid): bool => $uid > 0)));
        if ($mediaUids === [] || $fieldNames === []) {
            return;
        }

        // Empty defaults matter here: getReferences() treats a missing entry as
        // "not prefetched" and falls back to a per-record query.
        $this->referencesByMediaUid = $this->fileReferencePrefetcher->prefetch($mediaUids, $fieldNames, true);

        if (array_intersect(self::DESCRIBED_SOURCE_FIELDS, $fieldNames) !== []) {
            $this->describedSourceByReferenceUid = $this->fetchDescribedSourceFiles(
                $this->collectDescribedSourceCandidates($mediaUids)
            );
        }
    }

    /**
     * @return list<FileReference>
     */
    public function getReferences(int $mediaUid, string $fieldName): array
    {
        if ($mediaUid <= 0) {
            return [];
        }
        if (isset($this->referencesByMediaUid[$mediaUid][$fieldName])) {
            return $this->referencesByMediaUid[$mediaUid][$fieldName];
        }

        return array_values($this->fileRepository->findByRelation(self::FOREIGN_TABLE, $fieldName, $mediaUid));
    }

    public function getPublicUrl(FileReference $fileReference): string
    {
        $uid = $fileReference->getUid();
        if ($uid > 0 && isset($this->publicUrlByReferenceUid[$uid])) {
            return $this->publicUrlByReferenceUid[$uid];
        }
        $publicUrl = (string)$fileReference->getPublicUrl();
        if ($uid > 0) {
            $this->publicUrlByReferenceUid[$uid] = $publicUrl;
        }

        return $publicUrl;
    }

    public function getMimeType(FileReference $fileReference): string
    {
        $uid = $fileReference->getUid();
        if ($uid > 0 && isset($this->mimeTypeByReferenceUid[$uid])) {
            return $this->mimeTypeByReferenceUid[$uid];
        }
        $mimeType = (string)$fileReference->getMimeType();
        if ($uid > 0) {
            $this->mimeTypeByReferenceUid[$uid] = $mimeType;
        }

        return $mimeType;
    }

    /**
     * Size the file has on its storage right now, rather than the one sys_file has
     * indexed. A file replaced without re-indexing would otherwise be announced
     * with its predecessor's size — and contradict the size the player measures
     * for its own download button on the very same page.
     */
    public function getCurrentFileSize(FileReference $fileReference): int
    {
        $uid = $fileReference->getUid();
        if ($uid > 0 && isset($this->fileSizeByReferenceUid[$uid])) {
            return $this->fileSizeByReferenceUid[$uid];
        }

        try {
            $file = $fileReference->getOriginalFile();
            $size = (int)($file->getStorage()->getFileInfo($file)['size'] ?? 0);
        } catch (\Throwable) {
            // Storage offline or file gone: no size reads better than a wrong one.
            $size = 0;
        }

        if ($uid > 0) {
            $this->fileSizeByReferenceUid[$uid] = $size;
        }

        return $size;
    }

    /**
     * Public URL of the described source file of a caption/chapter track, i.e.
     * the alternative VTT used while audio description is on.
     */
    public function getDescribedSourceUrl(FileReference $trackFileReference): ?string
    {
        $uid = $trackFileReference->getUid();
        if ($uid > 0 && isset($this->describedSourceByReferenceUid[$uid])) {
            return $this->describedSourceByReferenceUid[$uid]->getPublicUrl();
        }

        return null;
    }

    public function inferMimeTypeFromUrl(string $url, string $fallbackMimeType = ''): string
    {
        $cacheKey = $url . '|' . $fallbackMimeType;
        if (isset($this->inferredMimeTypeByUrl[$cacheKey])) {
            return $this->inferredMimeTypeByUrl[$cacheKey];
        }

        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));

        $mimeType = match ($ext) {
            'mp3' => 'audio/mpeg',
            'ogg', 'oga' => 'audio/ogg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'm3u8' => 'application/vnd.apple.mpegurl',
            'mpd' => 'application/dash+xml',
            'mp4' => 'video/mp4',
            'm4v' => 'video/x-m4v',
            'webm' => 'video/webm',
            'ogv' => 'video/ogg',
            default => $fallbackMimeType !== '' ? $fallbackMimeType : 'application/octet-stream',
        };

        return $this->inferredMimeTypeByUrl[$cacheKey] = $mimeType;
    }

    /**
     * @param list<int> $mediaUids
     * @return list<int>
     */
    private function collectDescribedSourceCandidates(array $mediaUids): array
    {
        $uids = [];
        foreach ($mediaUids as $mediaUid) {
            foreach (self::DESCRIBED_SOURCE_FIELDS as $field) {
                foreach ($this->referencesByMediaUid[$mediaUid][$field] ?? [] as $fileReference) {
                    $uid = $fileReference->getUid();
                    if ($uid > 0) {
                        $uids[] = $uid;
                    }
                }
            }
        }

        return array_values(array_unique($uids));
    }

    /**
     * @param list<int> $sourceFileReferenceUids
     * @return array<int, FileReference>
     */
    private function fetchDescribedSourceFiles(array $sourceFileReferenceUids): array
    {
        $result = [];
        if ($sourceFileReferenceUids === []) {
            return $result;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $rows = $queryBuilder
            ->select('uid', 'uid_foreign')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('sys_file_reference')),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter('tx_desc_src_file')),
                $queryBuilder->expr()->in(
                    'uid_foreign',
                    $queryBuilder->createNamedParameter($sourceFileReferenceUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->orderBy('uid_foreign', 'ASC')
            ->addOrderBy('sorting_foreign', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $uid = (int)($row['uid'] ?? 0);
            $uidForeign = (int)($row['uid_foreign'] ?? 0);
            if ($uid <= 0 || $uidForeign <= 0) {
                continue;
            }
            if (isset($result[$uidForeign])) {
                continue;
            }
            try {
                $result[$uidForeign] = $this->resourceFactory->getFileReferenceObject($uid);
            } catch (ResourceDoesNotExistException) {
            }
        }

        return $result;
    }
}
