<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves translated sys_category titles for the frontend.
 *
 * Category MM relations always point at default-language category records
 * (`sys_language_uid` 0 or -1). This service overlays the connected
 * translation title when one exists for the requested language.
 */
final class CategoryTitleResolver
{
    private readonly ConnectionPool $connectionPool;

    public function __construct(?ConnectionPool $connectionPool = null)
    {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
    }

    /**
     * @param list<array{uid: int, title: string}> $categories Default-language categories
     * @return list<array{uid: int, title: string}>
     */
    public function localizeCategories(array $categories, int $languageId): array
    {
        if ($languageId <= 0 || $categories === []) {
            return $categories;
        }

        $defaultUids = array_values(array_filter(
            array_map(static fn (array $category): int => (int)($category['uid'] ?? 0), $categories),
            static fn (int $uid): bool => $uid > 0
        ));
        if ($defaultUids === []) {
            return $categories;
        }

        $translationsByParent = $this->fetchTranslationsByParentUids($defaultUids, $languageId);
        if ($translationsByParent === []) {
            return $categories;
        }

        $localized = [];
        foreach ($categories as $category) {
            $defaultUid = (int)($category['uid'] ?? 0);
            if ($defaultUid <= 0) {
                continue;
            }

            $translation = $translationsByParent[$defaultUid] ?? null;
            if ($translation !== null && trim($translation['title']) !== '') {
                $localized[] = [
                    'uid' => (int)$translation['uid'],
                    'title' => $translation['title'],
                ];
                continue;
            }

            $localized[] = $category;
        }

        return $localized;
    }

    /**
     * @param list<int> $parentUids Default-language `sys_category.uid` values
     * @return array<int, array{uid: int, title: string}> Indexed by parent uid
     */
    private function fetchTranslationsByParentUids(array $parentUids, int $languageId): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_category');
        $rows = $qb
            ->select('uid', 'l10n_parent', 'title')
            ->from('sys_category')
            ->where(
                $qb->expr()->in(
                    'l10n_parent',
                    $qb->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)
                ),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $parentUid = (int)($row['l10n_parent'] ?? 0);
            $uid = (int)($row['uid'] ?? 0);
            if ($parentUid <= 0 || $uid <= 0) {
                continue;
            }
            $out[$parentUid] = [
                'uid' => $uid,
                'title' => (string)($row['title'] ?? ''),
            ];
        }

        return $out;
    }
}
