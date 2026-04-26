<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Context\Context;

/**
 * Repository for VidPly media records with MM-relation resolution and language overlay.
 *
 * Uses constructor injection with fallback for TYPO3 13/14 compatibility.
 */
final class MediaRepository
{
    private const MEDIA_COLUMNS = [
        'uid', 'pid', 'sys_language_uid', 'l10n_parent', 'media_type', 'slug',
        'title', 'artist', 'description', 'long_description', 'duration', 'audio_description_duration',
        'hide_speed_button', 'allow_download', 'enable_floating_player',
        'enable_transcript', 'sign_language_display_mode', 'crdate', 'tstamp',
    ];

    private readonly ConnectionPool $connectionPool;
    private readonly Context $context;

    public function __construct(?ConnectionPool $connectionPool = null, ?Context $context = null)
    {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->context = $context ?? GeneralUtility::makeInstance(Context::class);
    }

    /**
     * Load media records for a content element, resolving MM relations and language overlays.
     *
     * $fallbackContentUid is the translation **source** (`tt_content.l18n_parent`) when
     * $contentUid is a localized CE. The MM table stores `uid_local` on either the
     * translated or the default record depending on how the content was created; when
     * the translation is set to *inherit* the default ("value of default language
     * is selected" / connected mode), `tt_content.tx_mpcvidply_media_items` is
     * `l10n_mode=exclude`: the playlist MM rows must be read from the **default
     * language** content element (`$fallbackContentUid` / l18n parent). Stale or
     * partial MM rows on the translated CE must not win (otherwise saving the
     * default list does not update the frontend in the secondary language).
     *
     * @return list<array<string, mixed>>
     */
    public function findByContentUid(int $contentUid, int $languageId = 0, int $fallbackContentUid = 0): array
    {
        if ($fallbackContentUid > 0 && $fallbackContentUid !== $contentUid) {
            $mmRelations = $this->fetchMmRelations($fallbackContentUid);
            if ($mmRelations === []) {
                $mmRelations = $this->fetchMmRelations($contentUid);
            }
        } else {
            $mmRelations = $this->fetchMmRelations($contentUid);
        }
        if ($mmRelations === []) {
            return [];
        }

        $referencedUids = $this->extractPositiveUids($mmRelations, 'uid_foreign');
        if ($referencedUids === []) {
            return [];
        }

        $referencedByUid = $this->fetchReferencedRecords($referencedUids);
        if ($referencedByUid === []) {
            return [];
        }

        $defaultUids = $this->resolveDefaultLanguageUids($referencedUids, $referencedByUid);
        if ($defaultUids === []) {
            return [];
        }

        $defaultByUid = $this->buildDefaultRecordMap($referencedByUid, $defaultUids);
        $translatedByParent = $languageId > 0
            ? $this->fetchTranslatedRecords($defaultUids, $languageId)
            : [];

        return $this->assembleOrderedResult(
            $mmRelations, $referencedByUid, $defaultByUid, $translatedByParent, $languageId
        );
    }

    /** @return list<array{uid_foreign: int|string, sorting: int|string}> */
    private function fetchMmRelations(int $contentUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_content_media_mm');
        return $qb
            ->select('uid_foreign', 'sorting')
            ->from('tx_mpcvidply_content_media_mm')
            ->where(
                $qb->expr()->eq('uid_local', $qb->createNamedParameter($contentUid, Connection::PARAM_INT))
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param list<array> $rows
     * @return list<int>
     */
    private function extractPositiveUids(array $rows, string $column): array
    {
        $uids = array_values(array_unique(array_map(
            static fn(array $row): int => (int)($row[$column] ?? 0),
            $rows
        )));
        return array_values(array_filter($uids, static fn(int $v): bool => $v > 0));
    }

    /**
     * @param list<int> $uids
     * @return array<int, array<string, mixed>>
     */
    private function fetchReferencedRecords(array $uids): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_media');
        $rows = $qb
            ->select(...self::MEDIA_COLUMNS)
            ->from('tx_mpcvidply_media')
            ->where(
                $qb->expr()->in('uid', $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
                ...$this->buildAccessConditions($qb)
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $uid = (int)($row['uid'] ?? 0);
            if ($uid > 0) {
                $map[$uid] = $row;
            }
        }
        return $map;
    }

    /**
     * @param list<int> $referencedUids
     * @param array<int, array<string, mixed>> $referencedByUid
     * @return list<int>
     */
    private function resolveDefaultLanguageUids(array $referencedUids, array $referencedByUid): array
    {
        $defaultUids = [];
        foreach ($referencedUids as $uid) {
            if (!isset($referencedByUid[$uid])) {
                continue;
            }
            $row = $referencedByUid[$uid];
            $refLang = (int)($row['sys_language_uid'] ?? 0);
            $defaultUid = $refLang === 0 ? $uid : (int)($row['l10n_parent'] ?? 0);
            if ($defaultUid > 0) {
                $defaultUids[] = $defaultUid;
            }
        }
        return array_values(array_unique($defaultUids));
    }

    /**
     * Build default-language record map, reusing already-fetched records where possible
     * to avoid a redundant database query on single-language sites.
     *
     * @param array<int, array<string, mixed>> $referencedByUid
     * @param list<int> $defaultUids
     * @return array<int, array<string, mixed>>
     */
    private function buildDefaultRecordMap(array $referencedByUid, array $defaultUids): array
    {
        $defaultByUid = [];
        foreach ($referencedByUid as $uid => $row) {
            if ((int)($row['sys_language_uid'] ?? 0) === 0 && in_array($uid, $defaultUids, true)) {
                $defaultByUid[$uid] = $row;
            }
        }

        $missingUids = array_values(array_filter(
            $defaultUids,
            static fn(int $uid): bool => !isset($defaultByUid[$uid])
        ));

        if ($missingUids !== []) {
            $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_media');
            $rows = $qb
                ->select(...self::MEDIA_COLUMNS)
                ->from('tx_mpcvidply_media')
                ->where(
                    $qb->expr()->in('uid', $qb->createNamedParameter($missingUids, Connection::PARAM_INT_ARRAY)),
                    $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                    ...$this->buildAccessConditions($qb)
                )
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $uid = (int)($row['uid'] ?? 0);
                if ($uid > 0) {
                    $defaultByUid[$uid] = $row;
                }
            }
        }

        return $defaultByUid;
    }

    /**
     * @param list<int> $defaultUids
     * @return array<int, array<string, mixed>>
     */
    private function fetchTranslatedRecords(array $defaultUids, int $languageId): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_media');
        $rows = $qb
            ->select(...self::MEDIA_COLUMNS)
            ->from('tx_mpcvidply_media')
            ->where(
                $qb->expr()->in('l10n_parent', $qb->createNamedParameter($defaultUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, Connection::PARAM_INT)),
                ...$this->buildAccessConditions($qb)
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $parent = (int)($row['l10n_parent'] ?? 0);
            if ($parent > 0) {
                $map[$parent] = $row;
            }
        }
        return $map;
    }

    /** @return list<array<string, mixed>> */
    private function assembleOrderedResult(
        array $mmRelations,
        array $referencedByUid,
        array $defaultByUid,
        array $translatedByParent,
        int $languageId
    ): array {
        $result = [];
        foreach ($mmRelations as $mmRelation) {
            $mediaUid = (int)($mmRelation['uid_foreign'] ?? 0);
            $sorting = (int)($mmRelation['sorting'] ?? 0);
            if ($mediaUid <= 0 || !isset($referencedByUid[$mediaUid])) {
                continue;
            }

            $referenced = $referencedByUid[$mediaUid];
            $referencedLanguageId = (int)($referenced['sys_language_uid'] ?? 0);
            $defaultLanguageUid = $referencedLanguageId === 0
                ? $mediaUid
                : (int)($referenced['l10n_parent'] ?? 0);

            $selected = $this->selectOverlayRecord(
                $referenced, $referencedLanguageId, $defaultLanguageUid,
                $defaultByUid, $translatedByParent, $languageId
            );

            if (is_array($selected)) {
                $selected['sorting'] = $sorting;
                $result[] = $selected;
            }
        }
        return $result;
    }

    /**
     * @return list<\TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression|string>
     */
    private function buildAccessConditions(\TYPO3\CMS\Core\Database\Query\QueryBuilder $qb): array
    {
        $conditions = [
            $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT)),
        ];

        try {
            $now = $this->context->getPropertyFromAspect('date', 'timestamp', 0);
        } catch (\Throwable) {
            $now = time();
        }

        $conditions[] = $qb->expr()->or(
            $qb->expr()->eq('starttime', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            $qb->expr()->lte('starttime', $qb->createNamedParameter($now, Connection::PARAM_INT))
        );
        $conditions[] = $qb->expr()->or(
            $qb->expr()->eq('endtime', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            $qb->expr()->gte('endtime', $qb->createNamedParameter($now, Connection::PARAM_INT))
        );

        return $conditions;
    }

    private function selectOverlayRecord(
        array $referenced,
        int $referencedLanguageId,
        int $defaultLanguageUid,
        array $defaultByUid,
        array $translatedByParent,
        int $languageId
    ): ?array {
        if ($languageId > 0) {
            if ($referencedLanguageId === $languageId) {
                return $referenced;
            }
            if ($defaultLanguageUid > 0 && isset($translatedByParent[$defaultLanguageUid])) {
                return $translatedByParent[$defaultLanguageUid];
            }
            if ($defaultLanguageUid > 0 && isset($defaultByUid[$defaultLanguageUid])) {
                return $defaultByUid[$defaultLanguageUid];
            }
            return null;
        }

        if ($referencedLanguageId === 0) {
            return $referenced;
        }
        if ($defaultLanguageUid > 0 && isset($defaultByUid[$defaultLanguageUid])) {
            return $defaultByUid[$defaultLanguageUid];
        }
        return null;
    }

    // -----------------------------------------------------------------------
    // Listview / Detail queries
    // -----------------------------------------------------------------------

    /**
     * Find a single media record by UID with language overlay resolution.
     * Accepts both default-language and translated UIDs.
     */
    public function findByUid(int $uid, int $languageId = 0): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_media');
        $row = $qb
            ->select(...self::MEDIA_COLUMNS)
            ->from('tx_mpcvidply_media')
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)),
                ...$this->buildAccessConditions($qb)
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return null;
        }

        $recordLanguageId = (int)($row['sys_language_uid'] ?? 0);
        $defaultLanguageUid = $recordLanguageId === 0 ? $uid : (int)($row['l10n_parent'] ?? 0);

        if ($defaultLanguageUid <= 0) {
            return $recordLanguageId === 0 ? $row : null;
        }

        $defaultRow = $recordLanguageId === 0 ? $row : $this->fetchSingleDefaultRow($defaultLanguageUid);
        if ($defaultRow === null) {
            return null;
        }

        if ($languageId > 0) {
            if ($recordLanguageId === $languageId) {
                return $row;
            }
            $translated = $this->fetchTranslatedRecords([$defaultLanguageUid], $languageId);
            if (isset($translated[$defaultLanguageUid])) {
                return $translated[$defaultLanguageUid];
            }
            return $defaultRow;
        }

        return $defaultRow;
    }

    /**
     * Find a single media record by its SEO slug (language-aware).
     */
    public function findBySlug(string $slug, int $languageId = 0): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_media');
        $row = $qb
            ->select(...self::MEDIA_COLUMNS)
            ->from('tx_mpcvidply_media')
            ->where(
                $qb->expr()->eq('slug', $qb->createNamedParameter($slug)),
                $qb->expr()->in(
                    'sys_language_uid',
                    $qb->createNamedParameter([0, $languageId], Connection::PARAM_INT_ARRAY)
                ),
                ...$this->buildAccessConditions($qb)
            )
            ->orderBy('sys_language_uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return null;
        }

        return $this->findByUid((int)($row['uid'] ?? 0), $languageId);
    }

    /**
     * Resolve media records selected manually for a listview row via the
     * tx_mpcvidply_listview_row_media_mm table.
     *
     * @return list<array<string, mixed>>
     */
    public function findByRowUid(int $rowUid, int $languageId = 0): array
    {
        if ($rowUid <= 0) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_listview_row_media_mm');
        $mmRelations = $qb
            ->select('uid_foreign', 'sorting')
            ->from('tx_mpcvidply_listview_row_media_mm')
            ->where(
                $qb->expr()->eq('uid_local', $qb->createNamedParameter($rowUid, Connection::PARAM_INT))
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($mmRelations === []) {
            return [];
        }

        $referencedUids = $this->extractPositiveUids($mmRelations, 'uid_foreign');
        if ($referencedUids === []) {
            return [];
        }

        $referencedByUid = $this->fetchReferencedRecords($referencedUids);
        if ($referencedByUid === []) {
            return [];
        }

        $defaultUids = $this->resolveDefaultLanguageUids($referencedUids, $referencedByUid);
        if ($defaultUids === []) {
            return [];
        }

        $defaultByUid = $this->buildDefaultRecordMap($referencedByUid, $defaultUids);
        $translatedByParent = $languageId > 0
            ? $this->fetchTranslatedRecords($defaultUids, $languageId)
            : [];

        return $this->assembleOrderedResult(
            $mmRelations,
            $referencedByUid,
            $defaultByUid,
            $translatedByParent,
            $languageId
        );
    }

    /**
     * Find media records assigned to one or more sys_category records.
     *
     * @param list<int> $categoryUids
     * @return list<array<string, mixed>>
     */
    public function findByCategories(
        array $categoryUids,
        int $languageId = 0,
        int $limit = 12,
        string $sortBy = 'sorting'
    ): array {
        $categoryUids = array_values(array_filter(
            array_map(static fn(int|string $v): int => (int)$v, $categoryUids),
            static fn(int $v): bool => $v > 0
        ));
        if ($categoryUids === [] || $limit <= 0) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_media');
        $qb
            ->select('media.uid', 'media.sys_language_uid', 'media.l10n_parent', 'media.crdate', 'media.title')
            ->from('tx_mpcvidply_media', 'media')
            ->join(
                'media',
                'sys_category_record_mm',
                'mm',
                (string)$qb->expr()->and(
                    $qb->expr()->eq('mm.uid_foreign', $qb->quoteIdentifier('media.uid')),
                    $qb->expr()->eq('mm.tablenames', $qb->createNamedParameter('tx_mpcvidply_media')),
                    $qb->expr()->eq('mm.fieldname', $qb->createNamedParameter('categories'))
                )
            )
            ->where(
                $qb->expr()->in(
                    'mm.uid_local',
                    $qb->createNamedParameter($categoryUids, Connection::PARAM_INT_ARRAY)
                ),
                $qb->expr()->eq('media.sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                ...$this->buildAccessConditionsForAlias($qb, 'media')
            )
            ->groupBy('media.uid')
            ->setMaxResults(max(1, $limit));

        switch ($sortBy) {
            case 'title_asc':
                $qb->orderBy('media.title', 'ASC');
                break;
            case 'crdate_desc':
                $qb->orderBy('media.crdate', 'DESC');
                break;
            case 'sorting':
            default:
                $qb->orderBy('mm.sorting_foreign', 'ASC')
                    ->addOrderBy('media.crdate', 'DESC');
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();
        if ($rows === []) {
            return [];
        }

        $uids = array_values(array_unique(array_map(
            static fn(array $row): int => (int)($row['uid'] ?? 0),
            $rows
        )));

        $fullRows = $this->fetchReferencedRecords($uids);
        $translated = $languageId > 0 ? $this->fetchTranslatedRecords($uids, $languageId) : [];

        $result = [];
        foreach ($rows as $row) {
            $uid = (int)($row['uid'] ?? 0);
            if ($uid <= 0 || !isset($fullRows[$uid])) {
                continue;
            }
            if ($languageId > 0 && isset($translated[$uid])) {
                $result[] = $translated[$uid];
            } else {
                $result[] = $fullRows[$uid];
            }
        }

        return $result;
    }

    /**
     * Find other media records that share categories with the given media record.
     * Useful for "You might also like" rows on detail pages.
     *
     * @return list<array<string, mixed>>
     */
    public function findNextInCategory(int $mediaUid, int $languageId = 0, int $limit = 6): array
    {
        if ($mediaUid <= 0 || $limit <= 0) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_category_record_mm');
        $categoryUids = $qb
            ->select('uid_local')
            ->from('sys_category_record_mm')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tx_mpcvidply_media')),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter('categories')),
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($mediaUid, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchFirstColumn();

        $categoryUids = array_values(array_unique(array_map(
            static fn(int|string $v): int => (int)$v,
            $categoryUids
        )));
        if ($categoryUids === []) {
            return [];
        }

        $records = $this->findByCategories($categoryUids, $languageId, $limit + 1, 'crdate_desc');

        return array_values(array_filter(
            $records,
            static fn(array $row): bool => (int)($row['uid'] ?? 0) !== $mediaUid
                && (int)($row['l10n_parent'] ?? 0) !== $mediaUid
        ));
    }

    private function fetchSingleDefaultRow(int $defaultUid): ?array
    {
        if ($defaultUid <= 0) {
            return null;
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_media');
        $row = $qb
            ->select(...self::MEDIA_COLUMNS)
            ->from('tx_mpcvidply_media')
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($defaultUid, Connection::PARAM_INT)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                ...$this->buildAccessConditions($qb)
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<\TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression|string>
     */
    private function buildAccessConditionsForAlias(
        \TYPO3\CMS\Core\Database\Query\QueryBuilder $qb,
        string $alias
    ): array {
        $conditions = [
            $qb->expr()->eq($alias . '.deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            $qb->expr()->eq($alias . '.hidden', $qb->createNamedParameter(0, Connection::PARAM_INT)),
        ];
        try {
            $now = $this->context->getPropertyFromAspect('date', 'timestamp', 0);
        } catch (\Throwable) {
            $now = time();
        }
        $conditions[] = $qb->expr()->or(
            $qb->expr()->eq($alias . '.starttime', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            $qb->expr()->lte($alias . '.starttime', $qb->createNamedParameter($now, Connection::PARAM_INT))
        );
        $conditions[] = $qb->expr()->or(
            $qb->expr()->eq($alias . '.endtime', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            $qb->expr()->gte($alias . '.endtime', $qb->createNamedParameter($now, Connection::PARAM_INT))
        );
        return $conditions;
    }
}
