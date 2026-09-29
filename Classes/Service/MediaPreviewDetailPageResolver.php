<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the frontend detail page for previewing a VidPly media record.
 *
 * Priority:
 * 1. Explicit listview detail page when the media item is manually selected in a shelf.
 * 2. A detail page placed inside the media storage folder (common editor setup).
 * 3. The first {@code mpc_vidply_detail} content element of the record's site.
 *
 * Candidates from 1. and 3. are limited to the site the media record is stored
 * in, so a preview never opens a detail page on another site's domain.
 */
final class MediaPreviewDetailPageResolver
{
    private const MAX_CANDIDATES = 100;

    private readonly ConnectionPool $connectionPool;
    private readonly SiteRecordScope $siteRecordScope;

    public function __construct(?ConnectionPool $connectionPool = null, ?SiteRecordScope $siteRecordScope = null)
    {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->siteRecordScope = $siteRecordScope ?? GeneralUtility::makeInstance(SiteRecordScope::class);
    }

    public function resolveDetailPageUidForMedia(int $defaultMediaUid, int $storagePid = 0): int
    {
        if ($defaultMediaUid <= 0) {
            return 0;
        }

        $siteIdentifier = $this->siteRecordScope->getSiteIdentifier($storagePid);

        $detailPageUid = $this->siteRecordScope->findFirstPageOfSite(
            $this->resolveFromListviewReferences($defaultMediaUid),
            $siteIdentifier
        );
        if ($detailPageUid > 0) {
            return $detailPageUid;
        }

        if ($storagePid > 0) {
            $detailPageUid = $this->resolveFromStorageFolder($storagePid);
            if ($detailPageUid > 0) {
                return $detailPageUid;
            }
        }

        return $this->siteRecordScope->findFirstPageOfSite($this->resolveSiteWideDetailPageUids(), $siteIdentifier);
    }

    /**
     * @return list<int>
     */
    private function resolveFromListviewReferences(int $mediaUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_listview_row_media_mm');
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $detailPageUid = $qb
            ->select('ce.tx_mpcvidply_detail_page')
            ->from('tx_mpcvidply_listview_row_media_mm', 'mm')
            ->innerJoin(
                'mm',
                'tx_mpcvidply_listview_row',
                'row',
                $qb->expr()->eq('row.uid', $qb->quoteIdentifier('mm.uid_local'))
            )
            ->innerJoin(
                'mm',
                'tt_content',
                'ce',
                $qb->expr()->eq('ce.uid', $qb->quoteIdentifier('row.parentid'))
            )
            ->where(
                $qb->expr()->eq('mm.uid_foreign', $qb->createNamedParameter($mediaUid, Connection::PARAM_INT)),
                $qb->expr()->eq('ce.CType', $qb->createNamedParameter('mpc_vidply_listview')),
                $qb->expr()->eq('ce.deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->gt('ce.tx_mpcvidply_detail_page', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('ce.uid', 'ASC')
            ->setMaxResults(self::MAX_CANDIDATES)
            ->executeQuery()
            ->fetchFirstColumn();

        return $this->toPositiveInts($detailPageUid);
    }

    private function resolveFromStorageFolder(int $storagePid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $pageUid = $qb
            ->select('ce.pid')
            ->from('tt_content', 'ce')
            ->innerJoin(
                'ce',
                'pages',
                'page',
                $qb->expr()->eq('page.uid', $qb->quoteIdentifier('ce.pid'))
            )
            ->where(
                $qb->expr()->eq('ce.CType', $qb->createNamedParameter('mpc_vidply_detail')),
                $qb->expr()->eq('ce.deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('page.deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->or(
                    $qb->expr()->eq('page.uid', $qb->createNamedParameter($storagePid, Connection::PARAM_INT)),
                    $qb->expr()->eq('page.pid', $qb->createNamedParameter($storagePid, Connection::PARAM_INT)),
                ),
            )
            ->orderBy('ce.pid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return ($pageUid !== false && (int)$pageUid > 0) ? (int)$pageUid : 0;
    }

    /**
     * @return list<int>
     */
    private function resolveSiteWideDetailPageUids(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $pageUid = $qb
            ->select('pid')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('CType', $qb->createNamedParameter('mpc_vidply_detail')),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->groupBy('pid')
            ->orderBy('pid', 'ASC')
            ->setMaxResults(self::MAX_CANDIDATES)
            ->executeQuery()
            ->fetchFirstColumn();

        return $this->toPositiveInts($pageUid);
    }

    /**
     * @param array<int, mixed> $values
     * @return list<int>
     */
    private function toPositiveInts(array $values): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $value): int => (int)$value, $values),
            static fn (int $value): bool => $value > 0
        ));
    }
}
