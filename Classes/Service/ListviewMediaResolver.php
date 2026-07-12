<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use Mpc\MpcVidply\Repository\MediaRepository;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the media records backing a VidPly listview content element.
 *
 * Shared between {@see \Mpc\MpcVidply\DataProcessing\ListviewProcessor} (which builds
 * the rendered "cards") and {@see VidPlyPageMediaResolver} (which builds structured
 * data), so both derive the same set of media records from a listview's rows.
 */
final class ListviewMediaResolver
{
    private readonly ConnectionPool $connectionPool;
    private readonly MediaRepository $mediaRepository;

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?MediaRepository $mediaRepository = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->mediaRepository = $mediaRepository ?? GeneralUtility::makeInstance(MediaRepository::class);
    }

    /**
     * Load the listview child rows (`tx_mpcvidply_listview_row`) for a content element.
     *
     * For a translated listview element (`l18n_parent` > 0), rows must follow the
     * default-language content element (same as "Value of default language" for the
     * playlist): we load by translation source uid when set, and fall back to the
     * current CE only if no rows exist (legacy data attached to the translation).
     *
     * @return list<array<string, mixed>>
     */
    public function resolveRows(int $contentUid, int $translationSourceUid, int $languageId): array
    {
        if ($contentUid <= 0) {
            return [];
        }

        // Localized inline children may hang off the default-language CE, the
        // translated CE, or both. Query every relevant parent id up front.
        $parentIds = [$contentUid];
        if ($translationSourceUid > 0 && $translationSourceUid !== $contentUid) {
            $parentIds[] = $translationSourceUid;
        }

        $rows = $this->queryListviewRowsByParentIds(
            array_values(array_unique($parentIds, SORT_NUMERIC)),
            $languageId
        );

        if ($languageId <= 0) {
            return array_values(array_filter(
                $rows,
                static fn (array $row): bool => (int)($row['sys_language_uid'] ?? 0) <= 0
            ));
        }

        $defaultsByUid = [];
        $translationsByParent = [];

        foreach ($rows as $row) {
            $language = (int)($row['sys_language_uid'] ?? 0);
            $uid = (int)($row['uid'] ?? 0);
            $parent = (int)($row['l10n_parent'] ?? $row['l18n_parent'] ?? 0);

            if ($language === 0 || $language === -1) {
                if ($uid > 0) {
                    $defaultsByUid[$uid] = $row;
                }
                continue;
            }

            if ($language === $languageId && $parent > 0) {
                $translationsByParent[$parent] = $row;
            }
        }

        // Connected-mode pages often render the default-language CE on a translated
        // page. Overlays may hang off the translated CE uid, so resolve them by
        // l10n_parent as well (not only via parentid in the first query).
        foreach ($this->fetchLocalizedOverlaysByDefaultUids(array_keys($defaultsByUid), $languageId) as $parentUid => $overlayRow) {
            $translationsByParent[$parentUid] = $overlayRow;
        }

        $merged = [];
        foreach ($defaultsByUid as $defaultUid => $defaultRow) {
            $merged[] = isset($translationsByParent[$defaultUid])
                ? $this->mergeLocalizedListviewRow($defaultRow, $translationsByParent[$defaultUid])
                : $defaultRow;
        }

        // Legacy rows attached only to the translated CE without a default sibling.
        foreach ($translationsByParent as $parentUid => $translatedRow) {
            if (!isset($defaultsByUid[$parentUid])) {
                $merged[] = $translatedRow;
            }
        }

        usort(
            $merged,
            static fn (array $a, array $b): int => (int)($a['sorting'] ?? 0) <=> (int)($b['sorting'] ?? 0)
        );

        return $merged;
    }

    /**
     * @param array<string, mixed> $defaultRow
     * @param array<string, mixed> $translatedRow
     * @return array<string, mixed>
     */
    private function mergeLocalizedListviewRow(array $defaultRow, array $translatedRow): array
    {
        $merged = $defaultRow;
        $merged['uid'] = (int)($translatedRow['uid'] ?? $defaultRow['uid'] ?? 0);
        $merged['l10n_parent'] = (int)($defaultRow['uid'] ?? 0);

        foreach (['headline', 'headline_link'] as $field) {
            $value = trim((string)($translatedRow[$field] ?? ''));
            if ($value !== '') {
                $merged[$field] = $translatedRow[$field];
            }
        }

        return $merged;
    }

    /**
     * Resolve the media records selected by a single listview row, applying the row's
     * selection mode (manual relation vs category), sort order and item limit.
     *
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    public function resolveMediaRecordsForRow(array $row, int $languageId): array
    {
        $rowUid = (int)($row['uid'] ?? 0);
        if ($rowUid <= 0) {
            return [];
        }

        $relationRowUid = $this->resolveRelationRowUid($row);

        $selectionMode = (string)($row['selection_mode'] ?? 'manual');
        $limit = max(1, (int)($row['limit_items'] ?? 12));
        $sortBy = (string)($row['sort_by'] ?? 'sorting');

        if ($selectionMode === 'category') {
            $categoryUids = $this->resolveCategoryUidsForRow($relationRowUid);

            return $this->mediaRepository->findByCategories($categoryUids, $languageId, $limit, $sortBy);
        }

        $mediaRecords = $this->mediaRepository->findByRowUid($relationRowUid, $languageId);
        if ($sortBy === 'title_asc') {
            usort(
                $mediaRecords,
                static fn (array $a, array $b): int => strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''))
            );
        } elseif ($sortBy === 'crdate_desc') {
            usort(
                $mediaRecords,
                static fn (array $a, array $b): int => (int)($b['crdate'] ?? 0) <=> (int)($a['crdate'] ?? 0)
            );
        }

        return array_slice($mediaRecords, 0, $limit);
    }

    /**
     * MM relations for localized inline child rows hang off the default-language uid.
     *
     * @param array<string, mixed> $row
     */
    public function resolveRelationRowUid(array $row): int
    {
        $rowUid = (int)($row['uid'] ?? 0);
        $parent = (int)($row['l10n_parent'] ?? $row['l18n_parent'] ?? 0);

        return $parent > 0 ? $parent : $rowUid;
    }

    /**
     * Convenience: all media records across every row of a listview content element,
     * flattened in row/sort order. Callers that need distinct media should dedupe.
     *
     * @param array<string, mixed> $contentElement A `tt_content` row of type `mpc_vidply_listview`
     * @return list<array<string, mixed>>
     */
    public function resolveMediaForContentElement(array $contentElement, int $languageId): array
    {
        $contentUid = (int)($contentElement['uid'] ?? 0);
        $translationSourceUid = (int)($contentElement['l18n_parent'] ?? $contentElement['l10n_parent'] ?? 0);

        $rows = $this->resolveRows($contentUid, $translationSourceUid, $languageId);

        $media = [];
        foreach ($rows as $row) {
            foreach ($this->resolveMediaRecordsForRow($row, $languageId) as $record) {
                $media[] = $record;
            }
        }

        return $media;
    }

    /**
     * @param list<int> $parentIds
     * @return list<array<string, mixed>>
     */
    private function queryListviewRowsByParentIds(array $parentIds, int $languageId): array
    {
        if ($parentIds === []) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_listview_row');

        return $qb
            ->select('*')
            ->from('tx_mpcvidply_listview_row')
            ->where(
                $qb->expr()->in('parentid', $qb->createNamedParameter($parentIds, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('parenttable', $qb->createNamedParameter('tt_content')),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->in(
                    'sys_language_uid',
                    $qb->createNamedParameter([0, -1, $languageId], Connection::PARAM_INT_ARRAY)
                )
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param list<int> $defaultRowUids
     * @return array<int, array<string, mixed>> Indexed by l10n_parent (default row uid)
     */
    private function fetchLocalizedOverlaysByDefaultUids(array $defaultRowUids, int $languageId): array
    {
        $defaultRowUids = array_values(array_filter(
            array_map(static fn (int|string $uid): int => (int)$uid, $defaultRowUids),
            static fn (int $uid): bool => $uid > 0
        ));
        if ($defaultRowUids === [] || $languageId <= 0) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_listview_row');
        $rows = $qb
            ->select('*')
            ->from('tx_mpcvidply_listview_row')
            ->where(
                $qb->expr()->in(
                    'l10n_parent',
                    $qb->createNamedParameter($defaultRowUids, Connection::PARAM_INT_ARRAY)
                ),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $overlays = [];
        foreach ($rows as $row) {
            $parentUid = (int)($row['l10n_parent'] ?? $row['l18n_parent'] ?? 0);
            if ($parentUid <= 0) {
                continue;
            }
            $overlays[$parentUid] = $row;
        }

        return $overlays;
    }

    /**
     * @return list<int>
     */
    private function resolveCategoryUidsForRow(int $rowUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_category_record_mm');
        $uids = $qb
            ->select('uid_local')
            ->from('sys_category_record_mm')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tx_mpcvidply_listview_row')),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter('categories')),
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($rowUid, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_unique(array_map(static fn (int|string $v): int => (int)$v, $uids)));
    }
}
