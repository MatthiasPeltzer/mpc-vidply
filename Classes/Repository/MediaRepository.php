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
        'uid', 'sys_language_uid', 'l10n_parent', 'media_type', 'hls_kind', 'dash_kind',
        'title', 'artist', 'description', 'duration', 'audio_description_duration',
        'hide_speed_button', 'enable_transcript', 'sign_language_display_mode',
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
     * @return list<array<string, mixed>>
     */
    public function findByContentUid(int $contentUid, int $languageId = 0): array
    {
        $mmRelations = $this->fetchMmRelations($contentUid);
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
}
