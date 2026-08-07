<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reads the sys_category relations of `tx_mpcvidply_media` records.
 *
 * Category MM relations always point at the default-language media record, so
 * translated records are looked up by their `l10n_parent` with a fallback to
 * their own uid (relations added directly to a translation). Titles are
 * localized through {@see CategoryTitleResolver}.
 *
 * @phpstan-type Category array{uid: int, title: string}
 */
final class MediaCategoryResolver
{
    private readonly ConnectionPool $connectionPool;
    private readonly CategoryTitleResolver $categoryTitleResolver;

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?CategoryTitleResolver $categoryTitleResolver = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->categoryTitleResolver = $categoryTitleResolver ?? GeneralUtility::makeInstance(CategoryTitleResolver::class);
    }

    /**
     * Categories of a list of media records, indexed by media uid.
     *
     * One query for all records — call this once per request and read the
     * result per record with {@see resolveForMedia()}.
     *
     * @param list<array<string, mixed>> $mediaRecords
     * @return array<int, list<Category>>
     */
    public function fetchForMediaRecords(array $mediaRecords, int $languageId): array
    {
        return $this->fetchByForeignMediaUids($this->collectRelationUids($mediaRecords), $languageId);
    }

    /**
     * Categories of a single media record.
     *
     * @param array<string, mixed> $media
     * @return list<Category>
     */
    public function fetchForMedia(array $media, int $languageId): array
    {
        return $this->resolveForMedia($media, $this->fetchForMediaRecords([$media], $languageId));
    }

    /**
     * Pick the categories of one record out of a map built by
     * {@see fetchForMediaRecords()}.
     *
     * @param array<string, mixed> $media
     * @param array<int, list<Category>> $categoryMap
     * @return list<Category>
     */
    public function resolveForMedia(array $media, array $categoryMap): array
    {
        $categories = $categoryMap[$this->resolveRelationUid($media)] ?? [];
        if ($categories === [] && (int)($media['l10n_parent'] ?? 0) > 0) {
            $categories = $categoryMap[(int)($media['uid'] ?? 0)] ?? [];
        }

        return array_values(array_filter(
            $categories,
            static fn (array $category): bool => trim((string)($category['title'] ?? '')) !== ''
        ));
    }

    /**
     * Media uids to query relations for: the default-language record of every
     * entry plus the translations themselves as a fallback.
     *
     * @param list<array<string, mixed>> $mediaRecords
     * @return list<int>
     */
    public function collectRelationUids(array $mediaRecords): array
    {
        $uids = [];
        foreach ($mediaRecords as $media) {
            if (!is_array($media)) {
                continue;
            }
            $relationUid = $this->resolveRelationUid($media);
            if ($relationUid > 0) {
                $uids[] = $relationUid;
            }
            $uid = (int)($media['uid'] ?? 0);
            if ($uid > 0 && (int)($media['l10n_parent'] ?? 0) > 0) {
                $uids[] = $uid;
            }
        }

        return array_values(array_unique($uids, SORT_NUMERIC));
    }

    /**
     * @param array<string, mixed> $media
     */
    private function resolveRelationUid(array $media): int
    {
        $l10nParent = (int)($media['l10n_parent'] ?? 0);

        return $l10nParent > 0 ? $l10nParent : (int)($media['uid'] ?? 0);
    }

    /**
     * @param list<int> $foreignMediaUids `sys_category_record_mm.uid_foreign` targets
     * @return array<int, list<Category>>
     */
    private function fetchByForeignMediaUids(array $foreignMediaUids, int $languageId): array
    {
        $foreignMediaUids = array_values(array_filter(
            array_map(static fn (int|string $uid): int => (int)$uid, $foreignMediaUids),
            static fn (int $uid): bool => $uid > 0
        ));
        if ($foreignMediaUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_category');
        $rows = $queryBuilder
            ->select('mm.uid_foreign', 'sys_category.uid', 'sys_category.title', 'mm.sorting')
            ->from('sys_category')
            ->join(
                'sys_category',
                'sys_category_record_mm',
                'mm',
                (string)$queryBuilder->expr()->and(
                    $queryBuilder->expr()->eq('mm.uid_local', $queryBuilder->quoteIdentifier('sys_category.uid')),
                    $queryBuilder->expr()->eq('mm.tablenames', $queryBuilder->createNamedParameter('tx_mpcvidply_media')),
                    $queryBuilder->expr()->eq('mm.fieldname', $queryBuilder->createNamedParameter('categories')),
                    $queryBuilder->expr()->in(
                        'mm.uid_foreign',
                        $queryBuilder->createNamedParameter($foreignMediaUids, Connection::PARAM_INT_ARRAY)
                    )
                )
            )
            ->where(
                $queryBuilder->expr()->eq('sys_category.deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_category.hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->lte('sys_category.sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('mm.uid_foreign', 'ASC')
            ->addOrderBy('mm.sorting', 'ASC')
            ->addOrderBy('sys_category.title', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        /** @var array<int, list<Category>> $categoriesByMediaUid */
        $categoriesByMediaUid = [];
        $seen = [];
        foreach ($rows as $row) {
            $mediaUid = (int)($row['uid_foreign'] ?? 0);
            $categoryUid = (int)($row['uid'] ?? 0);
            if ($mediaUid <= 0 || $categoryUid <= 0) {
                continue;
            }
            $key = $mediaUid . '-' . $categoryUid;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $categoriesByMediaUid[$mediaUid] ??= [];
            $categoriesByMediaUid[$mediaUid][] = ['uid' => $categoryUid, 'title' => (string)($row['title'] ?? '')];
        }

        foreach ($categoriesByMediaUid as $mediaUid => $categories) {
            $categoriesByMediaUid[$mediaUid] = $this->categoryTitleResolver->localizeCategories($categories, $languageId);
        }

        return $categoriesByMediaUid;
    }
}
