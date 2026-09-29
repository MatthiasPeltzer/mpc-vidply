<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Keeps records and pages of one site out of another site's frontend.
 *
 * Media slugs are only unique per site and `?media=<uid>` addresses any record
 * in the database, so a record is only accepted when its storage page belongs
 * to the site of the request. Records stored outside of every site tree (e.g. a
 * shared storage folder on the root level) stay usable by all sites.
 */
final class SiteRecordScope
{
    /**
     * @var array<int, string|null>
     */
    private array $siteIdentifierByPageId = [];

    public function __construct(private ?SiteFinder $siteFinder = null) {}

    /**
     * Identifier of the site a page belongs to, or null when it is outside of
     * every site tree.
     */
    public function getSiteIdentifier(int $pageId): ?string
    {
        if ($pageId <= 0) {
            return null;
        }
        if (array_key_exists($pageId, $this->siteIdentifierByPageId)) {
            return $this->siteIdentifierByPageId[$pageId];
        }

        try {
            $this->siteFinder ??= GeneralUtility::makeInstance(SiteFinder::class);
            $identifier = $this->siteFinder->getSiteByPageId($pageId)->getIdentifier();
        } catch (SiteNotFoundException) {
            $identifier = null;
        }

        return $this->siteIdentifierByPageId[$pageId] = $identifier;
    }

    /**
     * Whether a page (or a record stored on it) may be used by the given site.
     * Without a real site there is nothing to scope against.
     */
    public function isPageInSite(int $pageId, ?SiteInterface $site): bool
    {
        if (!$site instanceof Site) {
            return true;
        }
        $pageSite = $this->getSiteIdentifier($pageId);

        return $pageSite === null || $pageSite === $site->getIdentifier();
    }

    /**
     * @param array<string, mixed>|null $record
     * @return array<string, mixed>|null
     */
    public function scopeRecord(?array $record, ?SiteInterface $site): ?array
    {
        if ($record === null) {
            return null;
        }

        return $this->isPageInSite((int)($record['pid'] ?? 0), $site) ? $record : null;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    public function filterRecords(array $records, ?SiteInterface $site): array
    {
        return array_values(array_filter(
            $records,
            fn (array $record): bool => $this->isPageInSite((int)($record['pid'] ?? 0), $site)
        ));
    }

    /**
     * First page of the list that belongs to the site with the given identifier.
     * Without a site identifier (the reference page is outside every site tree)
     * there is nothing to scope against and the first page wins.
     *
     * @param list<int> $pageIds
     */
    public function findFirstPageOfSite(array $pageIds, ?string $siteIdentifier): int
    {
        if ($siteIdentifier === null) {
            return $pageIds[0] ?? 0;
        }
        foreach ($pageIds as $pageId) {
            if ($this->getSiteIdentifier($pageId) === $siteIdentifier) {
                return $pageId;
            }
        }

        return 0;
    }
}
