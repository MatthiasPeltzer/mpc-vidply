<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use Mpc\MpcVidply\DataProcessing\VidPlyProcessor;
use Mpc\MpcVidply\Repository\MediaRepository;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * Resolves VidPly media on the current frontend page for structured data output.
 *
 * Returns a single {@see VideoObject} context on detail views and on pages with
 * exactly one distinct media item; otherwise an {@see ItemList} of all videos
 * from every inline {@code mpc_vidply} player (including playlists).
 */
final class VidPlyPageMediaResolver
{
    private readonly DetailRequestResolver $detailResolver;
    private readonly MediaRepository $mediaRepository;
    private readonly VidPlyProcessor $vidPlyProcessor;
    private readonly ListviewMediaResolver $listviewMediaResolver;
    private readonly FileReferencePrefetcher $fileReferencePrefetcher;
    private readonly SiteRecordScope $siteRecordScope;
    private readonly ConnectionPool $connectionPool;
    private readonly DetailUrlBuilder $detailUrlBuilder;

    /**
     * Fallback detail page per site identifier ("" for pages outside any site).
     *
     * @var array<string, int>
     */
    private array $detailPageUidCache = [];

    /**
     * Poster URL per media uid, filled in one query per structured-data run.
     *
     * @var array<int, string|null>
     */
    private array $posterUrlByMediaUid = [];

    public function __construct(
        ?DetailRequestResolver $detailResolver = null,
        ?MediaRepository $mediaRepository = null,
        ?VidPlyProcessor $vidPlyProcessor = null,
        ?ListviewMediaResolver $listviewMediaResolver = null,
        ?FileReferencePrefetcher $fileReferencePrefetcher = null,
        ?ConnectionPool $connectionPool = null,
        ?DetailUrlBuilder $detailUrlBuilder = null,
        ?SiteRecordScope $siteRecordScope = null
    ) {
        $this->detailResolver = $detailResolver ?? GeneralUtility::makeInstance(DetailRequestResolver::class);
        $this->mediaRepository = $mediaRepository ?? GeneralUtility::makeInstance(MediaRepository::class);
        $this->vidPlyProcessor = $vidPlyProcessor ?? GeneralUtility::makeInstance(VidPlyProcessor::class);
        $this->listviewMediaResolver = $listviewMediaResolver ?? GeneralUtility::makeInstance(ListviewMediaResolver::class);
        $this->fileReferencePrefetcher = $fileReferencePrefetcher ?? GeneralUtility::makeInstance(FileReferencePrefetcher::class);
        $this->siteRecordScope = $siteRecordScope ?? GeneralUtility::makeInstance(SiteRecordScope::class);
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->detailUrlBuilder = $detailUrlBuilder ?? GeneralUtility::makeInstance(DetailUrlBuilder::class);
    }

    /**
     * @return array{
     *     mode: 'single'|'list',
     *     pageUrl: string,
     *     pageName: string,
     *     items: list<array{
     *         media: array<string, mixed>,
     *         vidply: array<string, mixed>,
     *         pageUrl: string,
     *         itemUrl: string,
     *         posterUrl: ?string
     *     }>
     * }|null
     */
    public function resolveStructuredData(ServerRequestInterface $request, ContentObjectRenderer $cObj): ?array
    {
        $languageId = FrontendLanguageResolver::resolveLanguageId($request);
        $pageId = $this->resolvePageId($request);
        if ($pageId <= 0) {
            return null;
        }

        $pageUrl = $this->absolutePageUrl($cObj, $pageId);
        $pageName = $this->resolvePageTitle($request);

        $detailMedia = $this->detailResolver->resolveFromRequest($request);
        if ($detailMedia !== null) {
            $contentElement = $this->findDetailContentElement($pageId, $languageId);
            $this->prefetchPosterUrls([$detailMedia]);
            if ($contentElement === null) {
                return null;
            }

            $item = $this->buildItemContext($detailMedia, $contentElement, $request, $cObj, $languageId, true, $pageUrl);
            if ($item === null) {
                return null;
            }

            return [
                'mode' => 'single',
                'pageUrl' => $item['pageUrl'],
                'pageName' => $pageName,
                'items' => [$item],
            ];
        }

        $mediaEntries = $this->collectGalleryMediaEntries($pageId, $languageId);
        if ($mediaEntries === []) {
            return null;
        }
        $this->prefetchPosterUrls(array_map(static fn (array $entry): array => $entry['media'], $mediaEntries));

        $seenDefaultUids = [];
        $items = [];
        foreach ($mediaEntries as $entry) {
            $media = $entry['media'];
            $defaultUid = $this->resolveDefaultMediaUid($media);
            if ($defaultUid <= 0 || isset($seenDefaultUids[$defaultUid])) {
                continue;
            }
            $seenDefaultUids[$defaultUid] = true;

            $item = $this->buildItemContext(
                $media,
                $entry['contentElement'],
                $request,
                $cObj,
                $languageId,
                false,
                $pageUrl,
                $entry['detailPageUid'],
            );
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            return null;
        }

        return [
            'mode' => count($items) === 1 ? 'single' : 'list',
            'pageUrl' => $pageUrl,
            'pageName' => $pageName,
            'items' => $items,
        ];
    }

    /**
     * Collect every VidPly media record on the page, from both inline `mpc_vidply`
     * players (including playlists) and `mpc_vidply_listview` shelves. Each entry
     * carries its owning content element and the detail page configured on a
     * listview (`tx_mpcvidply_detail_page`), if any.
     *
     * @return list<array{media: array<string, mixed>, contentElement: array<string, mixed>, detailPageUid: int}>
     */
    private function collectGalleryMediaEntries(int $pageId, int $languageId): array
    {
        $entries = [];

        foreach ($this->findAllContentElementsByType($pageId, 'mpc_vidply', $languageId) as $contentElement) {
            $contentUid = (int)($contentElement['uid'] ?? 0);
            $translationSourceUid = (int)($contentElement['l18n_parent'] ?? $contentElement['l10n_parent'] ?? 0);
            $mediaRecords = $this->mediaRepository->findByContentUid(
                $contentUid,
                $languageId,
                $translationSourceUid > 0 ? $translationSourceUid : 0
            );
            foreach ($mediaRecords as $media) {
                $entries[] = ['media' => $media, 'contentElement' => $contentElement, 'detailPageUid' => 0];
            }
        }

        foreach ($this->findAllContentElementsByType($pageId, 'mpc_vidply_listview', $languageId) as $contentElement) {
            $detailPageUid = (int)($contentElement['tx_mpcvidply_detail_page'] ?? 0);
            foreach ($this->listviewMediaResolver->resolveMediaForContentElement($contentElement, $languageId) as $media) {
                $entries[] = ['media' => $media, 'contentElement' => $contentElement, 'detailPageUid' => $detailPageUid];
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $media
     * @param array<string, mixed> $contentElement
     * @return array{
     *     media: array<string, mixed>,
     *     vidply: array<string, mixed>,
     *     pageUrl: string,
     *     itemUrl: string,
     *     posterUrl: ?string
     * }|null
     */
    private function buildItemContext(
        array $media,
        array $contentElement,
        ServerRequestInterface $request,
        ContentObjectRenderer $cObj,
        int $languageId,
        bool $isDetailView,
        string $galleryPageUrl,
        int $detailPageUidOverride = 0
    ): ?array {
        $mediaUid = (int)($media['uid'] ?? 0);
        if ($mediaUid <= 0) {
            return null;
        }

        $defaultUid = $this->resolveDefaultMediaUid($media);
        $pageId = (int)($contentElement['pid'] ?? $this->resolvePageId($request));

        $watchUrl = $isDetailView
            ? $this->detailUrlBuilder->build(
                $cObj,
                $pageId,
                $defaultUid,
                trim((string)($media['slug'] ?? '')),
                $languageId,
                true
            )
            : $this->resolveWatchUrl(
                $cObj,
                $defaultUid,
                trim((string)($media['slug'] ?? '')),
                $languageId,
                $galleryPageUrl,
                $detailPageUidOverride,
                $pageId
            );

        $embedPageUrl = $isDetailView ? $watchUrl : $galleryPageUrl;

        // Detail views render a full player anyway, so reuse the complete assembly.
        // Gallery and list items only need source URLs for JSON-LD, so use the
        // lightweight structured-data context to avoid assembling a player per item.
        $vidply = $isDetailView
            ? $this->vidPlyProcessor->assembleForMediaRecords([$media], $contentElement, $request, $languageId)
            : $this->vidPlyProcessor->assembleStructuredDataContext([$media], $request);

        return [
            'media' => $media,
            'vidply' => $vidply,
            'pageUrl' => $embedPageUrl,
            'itemUrl' => $watchUrl,
            'posterUrl' => $this->resolvePosterUrl($mediaUid),
        ];
    }

    private function resolveWatchUrl(
        ContentObjectRenderer $cObj,
        int $defaultMediaUid,
        string $slug,
        int $languageId,
        string $galleryPageUrl,
        int $detailPageUidOverride,
        int $galleryPageId
    ): string {
        $detailPageUid = $this->resolveEffectiveDetailPageUid($detailPageUidOverride, $galleryPageId);
        if ($detailPageUid > 0) {
            $detailUrl = $this->detailUrlBuilder->build($cObj, $detailPageUid, $defaultMediaUid, $slug, $languageId, true);
            if ($detailUrl !== '') {
                return $detailUrl;
            }
        }

        if ($galleryPageUrl === '') {
            return '';
        }

        return rtrim($galleryPageUrl, '/') . '#media-' . $defaultMediaUid;
    }

    /**
     * Resolve the detail page to link a gallery/list item to, in priority order:
     *
     * 1. The detail page explicitly configured on the owning listview CE
     *    (`tx_mpcvidply_detail_page`).
     * 2. A `mpc_vidply_detail` content element on the same page (the page links to itself).
     * 3. The first detail page of the gallery page's site as a last resort.
     */
    private function resolveEffectiveDetailPageUid(int $detailPageUidOverride, int $galleryPageId): int
    {
        if ($detailPageUidOverride > 0) {
            return $detailPageUidOverride;
        }

        if ($galleryPageId > 0 && $this->findFirstContentElementByType($galleryPageId, 'mpc_vidply_detail', 0) !== null) {
            return $galleryPageId;
        }

        return $this->resolveDetailPageUid($this->siteRecordScope->getSiteIdentifier($galleryPageId));
    }

    /**
     * @param array<string, mixed> $media
     */
    private function resolveDefaultMediaUid(array $media): int
    {
        $parent = (int)($media['l10n_parent'] ?? 0);

        return $parent > 0 ? $parent : (int)($media['uid'] ?? 0);
    }

    /**
     * First page with a detail content element in the given site; a detail
     * page of another site would produce a link to a foreign domain.
     */
    private function resolveDetailPageUid(?string $siteIdentifier): int
    {
        $cacheKey = $siteIdentifier ?? '';
        if (isset($this->detailPageUidCache[$cacheKey])) {
            return $this->detailPageUidCache[$cacheKey];
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
            $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
            $pageUids = $qb
                ->select('pid')
                ->from('tt_content')
                ->where(
                    $qb->expr()->eq('CType', $qb->createNamedParameter('mpc_vidply_detail'))
                )
                ->groupBy('pid')
                ->orderBy('pid', 'ASC')
                ->setMaxResults(100)
                ->executeQuery()
                ->fetchFirstColumn();
        } catch (\Throwable) {
            return $this->detailPageUidCache[$cacheKey] = 0;
        }

        return $this->detailPageUidCache[$cacheKey] = $this->siteRecordScope->findFirstPageOfSite(
            array_map(static fn (mixed $pid): int => (int)$pid, $pageUids),
            $siteIdentifier
        );
    }

    /**
     * Load the posters of all media records in one query. Translations
     * without a poster of their own use the default-language poster.
     *
     * @param list<array<string, mixed>> $mediaRecords
     */
    private function prefetchPosterUrls(array $mediaRecords): void
    {
        $uids = [];
        foreach ($mediaRecords as $media) {
            $uids[] = (int)($media['uid'] ?? 0);
            $uids[] = (int)($media['l10n_parent'] ?? 0);
        }
        $uids = array_values(array_unique(array_filter($uids, static fn (int $uid): bool => $uid > 0)));
        if ($uids === []) {
            return;
        }

        $references = $this->fileReferencePrefetcher->prefetchField($uids, 'poster');
        foreach ($mediaRecords as $media) {
            $uid = (int)($media['uid'] ?? 0);
            $reference = $references[$uid][0] ?? $references[(int)($media['l10n_parent'] ?? 0)][0] ?? null;
            $url = $reference !== null ? (string)$reference->getPublicUrl() : '';
            $this->posterUrlByMediaUid[$uid] = $url !== '' ? $url : null;
        }
    }

    private function resolvePosterUrl(int $mediaUid): ?string
    {
        if ($mediaUid <= 0) {
            return null;
        }
        if (!array_key_exists($mediaUid, $this->posterUrlByMediaUid)) {
            $this->prefetchPosterUrls([['uid' => $mediaUid]]);
        }

        return $this->posterUrlByMediaUid[$mediaUid] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findDetailContentElement(int $pageId, int $languageId): ?array
    {
        return $this->findFirstContentElementByType($pageId, 'mpc_vidply_detail', $languageId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * Content elements of the current language (plus "all languages"). A
     * language without any translated element of this type falls back to the
     * default language, as the page itself would.
     *
     * @return list<array<string, mixed>>
     */
    private function findAllContentElementsByType(int $pageId, string $cType, int $languageId): array
    {
        if ($pageId <= 0) {
            return [];
        }

        $rows = $this->fetchContentElements($pageId, $cType, [max(0, $languageId), -1]);
        if ($rows === [] && $languageId > 0) {
            $rows = $this->fetchContentElements($pageId, $cType, [0, -1]);
        }

        return $rows;
    }

    /**
     * @param list<int> $languageIds
     * @return list<array<string, mixed>>
     */
    private function fetchContentElements(int $pageId, string $cType, array $languageIds): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $rows = $qb
            ->select('*')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pageId, Connection::PARAM_INT)),
                $qb->expr()->eq('CType', $qb->createNamedParameter($cType)),
                $qb->expr()->in('sys_language_uid', $qb->createNamedParameter($languageIds, Connection::PARAM_INT_ARRAY))
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_filter($rows, static fn (array $row): bool => $row !== []));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findFirstContentElementByType(int $pageId, string $cType, int $languageId): ?array
    {
        $rows = $this->findAllContentElementsByType($pageId, $cType, $languageId);

        return $rows[0] ?? null;
    }

    private function absolutePageUrl(ContentObjectRenderer $cObj, int $pageUid): string
    {
        if ($pageUid <= 0) {
            return '';
        }

        try {
            return (string)$cObj->typoLink_URL([
                'parameter' => $pageUid,
                'forceAbsoluteUrl' => true,
            ]);
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolvePageTitle(ServerRequestInterface $request): string
    {
        $pageInformation = $request->getAttribute('frontend.page.information');
        if ($pageInformation instanceof PageInformation) {
            return trim((string)($pageInformation->getPageRecord()['title'] ?? ''));
        }

        return '';
    }

    private function resolvePageId(ServerRequestInterface $request): int
    {
        $pageInformation = $request->getAttribute('frontend.page.information');
        if ($pageInformation instanceof PageInformation) {
            return (int)$pageInformation->getId();
        }

        $routing = $request->getAttribute('routing');
        if ($routing !== null && method_exists($routing, 'getPageId')) {
            return (int)$routing->getPageId();
        }

        return 0;
    }
}
