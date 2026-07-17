<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Ensures localized `tx_mpcvidply_listview_row` child records exist for translated
 * listview content elements so editors can translate row headlines in the backend.
 */
final class ListviewRowLocalizationService
{
    private const ROW_TABLE = 'tx_mpcvidply_listview_row';
    private const PARENT_TABLE = 'tt_content';
    private const PARENT_FIELD = 'tx_mpcvidply_listview_rows';

    /**
     * Fields copied from the default-language row when a localized overlay is
     * first created. Headline and headline_link start as copies; editors translate
     * them on the localized record.
     *
     * @var list<string>
     */
    private const COPY_ON_CREATE = [
        'headline',
        'headline_link',
        'layout',
        'card_style',
        'limit_items',
        'enable_pagination',
        'pagination_per_page',
        'sort_by',
        'selection_mode',
        'sorting',
        'hidden',
    ];

    /**
     * Structural fields kept in sync from the default row on later source saves.
     *
     * @var list<string>
     */
    private const SYNC_FROM_SOURCE = [
        'layout',
        'card_style',
        'limit_items',
        'enable_pagination',
        'pagination_per_page',
        'sort_by',
        'selection_mode',
        'sorting',
        'hidden',
    ];

    /**
     * @var list<string>
     */
    private const STRING_ROW_FIELDS = [
        'headline',
        'headline_link',
        'layout',
        'card_style',
        'selection_mode',
        'sort_by',
    ];

    private readonly ConnectionPool $connectionPool;

    public function __construct(
        ?ConnectionPool $connectionPool = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
    }

    public function ensureLocalizedRowsForTranslation(int $sourceContentUid, int $targetContentUid, int $languageId): void
    {
        if ($sourceContentUid <= 0 || $targetContentUid <= 0 || $languageId <= 0) {
            return;
        }

        $defaultRows = $this->fetchDefaultRowsForContentElement($sourceContentUid);
        if ($defaultRows === []) {
            return;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::ROW_TABLE);
        $now = time();

        foreach ($defaultRows as $defaultRow) {
            $defaultUid = (int)($defaultRow['uid'] ?? 0);
            if ($defaultUid <= 0) {
                continue;
            }

            $localizedRow = $this->fetchLocalizedRowForDefault($defaultUid, $languageId);
            if ($localizedRow === null) {
                $insert = [
                    'pid' => (int)($defaultRow['pid'] ?? 0),
                    'parentid' => $targetContentUid,
                    'parenttable' => self::PARENT_TABLE,
                    'parentfield' => self::PARENT_FIELD,
                    'sys_language_uid' => $languageId,
                    'l10n_parent' => $defaultUid,
                    'crdate' => $now,
                    'tstamp' => $now,
                    'deleted' => 0,
                ];
                foreach (self::COPY_ON_CREATE as $field) {
                    $insert[$field] = $this->normalizeRowFieldValue($field, $defaultRow);
                }
                $connection->insert(self::ROW_TABLE, $insert);
                continue;
            }

            $this->syncStructuralFieldsFromDefault($defaultRow, (int)($localizedRow['uid'] ?? 0), $now);
        }
    }

    public function ensureLocalizedRowsForAllTranslations(int $sourceContentUid): void
    {
        if ($sourceContentUid <= 0) {
            return;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::PARENT_TABLE);
        $translations = $qb
            ->select('uid', 'sys_language_uid')
            ->from(self::PARENT_TABLE)
            ->where(
                $qb->expr()->eq('l18n_parent', $qb->createNamedParameter($sourceContentUid, Connection::PARAM_INT)),
                $qb->expr()->eq('CType', $qb->createNamedParameter('mpc_vidply_listview')),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($translations as $translation) {
            $targetUid = (int)($translation['uid'] ?? 0);
            $languageId = (int)($translation['sys_language_uid'] ?? 0);
            if ($targetUid <= 0 || $languageId <= 0) {
                continue;
            }
            $this->ensureLocalizedRowsForTranslation($sourceContentUid, $targetUid, $languageId);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchDefaultRowsForContentElement(int $contentUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::ROW_TABLE);

        return $qb
            ->select('*')
            ->from(self::ROW_TABLE)
            ->where(
                $qb->expr()->eq('parentid', $qb->createNamedParameter($contentUid, Connection::PARAM_INT)),
                $qb->expr()->eq('parenttable', $qb->createNamedParameter(self::PARENT_TABLE)),
                $qb->expr()->eq('parentfield', $qb->createNamedParameter(self::PARENT_FIELD)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->lte('sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchLocalizedRowForDefault(int $defaultRowUid, int $languageId): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::ROW_TABLE);
        $row = $qb
            ->select('*')
            ->from(self::ROW_TABLE)
            ->where(
                $qb->expr()->eq('l10n_parent', $qb->createNamedParameter($defaultRowUid, Connection::PARAM_INT)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $defaultRow
     */
    private function syncStructuralFieldsFromDefault(array $defaultRow, int $localizedRowUid, int $timestamp): void
    {
        if ($localizedRowUid <= 0) {
            return;
        }

        $update = ['tstamp' => $timestamp];
        foreach (self::SYNC_FROM_SOURCE as $field) {
            $update[$field] = $this->normalizeRowFieldValue($field, $defaultRow);
        }

        $this->connectionPool->getConnectionForTable(self::ROW_TABLE)->update(
            self::ROW_TABLE,
            $update,
            ['uid' => $localizedRowUid]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function normalizeRowFieldValue(string $field, array $row): string|int
    {
        $value = $row[$field] ?? null;

        if (in_array($field, self::STRING_ROW_FIELDS, true)) {
            return (string)($value ?? '');
        }

        return (int)($value ?? 0);
    }
}
