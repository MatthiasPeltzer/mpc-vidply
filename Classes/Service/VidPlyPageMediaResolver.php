<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use Mpc\MpcVidply\DataProcessing\VidPlyProcessor;
use Mpc\MpcVidply\Repository\MediaRepository;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Resource\FileRepository;
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
    private readonly FileRepository $fileRepository;
    private readonly ConnectionPool $connectionPool;

    private ?int $detailPageUidCache = null;

    public function __construct(
        ?DetailRequestResolver $detailResolver = null,
        ?MediaRepository $mediaRepository = null,
        ?VidPlyProcessor $vidPlyProcessor = null,
        ?ListviewMediaResolver $listviewMediaResolver = null,
        ?FileRepository $fileRepository = null,
        ?ConnectionPool $connectionPool = null
    ) {
        $this->detailResolver = $detailResolver ?? GeneralUtility::makeInstance(DetailRequestResolver::class);
        $this->mediaRepository = $mediaRepository ?? GeneralUtility::makeInstance(MediaRepository::class);
        $this->vidPlyProcessor = $vidPlyProcessor ?? GeneralUtility::makeInstance(VidPlyProcessor::class);
        $this->listviewMediaResolver = $listviewMediaResolver ?? GeneralUtility::makeInstance(ListviewMediaResolver::class);
        $this->fileRepository = $fileRepository ?? GeneralUtility::makeInstance(FileRepository::class);
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
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
            $contentElement = $this->findDetailContentElement($pageId);
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

        foreach ($this->findAllContentElementsByType($pageId, 'mpc_vidply') as $contentElement) {
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

        foreach ($this->findAllContentElementsByType($pageId, 'mpc_vidply_listview') as $contentElement) {
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
            ? $this->buildDetailUrl(
                $cObj,
                $pageId,
                $defaultUid,
                trim((string)($media['slug'] ?? '')),
                $languageId
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
            $detailUrl = $this->buildDetailUrl($cObj, $detailPageUid, $defaultMediaUid, $slug, $languageId);
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
     * 3. The site-wide first detail page as a last resort.
     */
    private function resolveEffectiveDetailPageUid(int $detailPageUidOverride, int $galleryPageId): int
    {
        if ($detailPageUidOverride > 0) {
            return $detailPageUidOverride;
        }

        if ($galleryPageId > 0 && $this->findFirstContentElementByType($galleryPageId, 'mpc_vidply_detail') !== null) {
            return $galleryPageId;
        }

        return $this->resolveDetailPageUid();
    }

    /**
     * @param array<string, mixed> $media
     */
    private function resolveDefaultMediaUid(array $media): int
    {
        $parent = (int)($media['l10n_parent'] ?? 0);

        return $parent > 0 ? $parent : (int)($media['uid'] ?? 0);
    }

    private function resolveDetailPageUid(): int
    {
        if ($this->detailPageUidCache !== null) {
            return $this->detailPageUidCache;
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
            $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
            $pageUid = $qb
                ->select('pid')
                ->from('tt_content')
                ->where(
                    $qb->expr()->eq('CType', $qb->createNamedParameter('mpc_vidply_detail'))
                )
                ->orderBy('pid', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        } catch (\Throwable) {
            return $this->detailPageUidCache = 0;
        }

        return $this->detailPageUidCache = ($pageUid !== false && (int)$pageUid > 0) ? (int)$pageUid : 0;
    }

    private function resolvePosterUrl(int $mediaUid): ?string
    {
        if ($mediaUid <= 0) {
            return null;
        }

        $references = $this->fileRepository->findByRelation('tx_mpcvidply_media', 'poster', $mediaUid);
        if ($references === []) {
            return null;
        }

        $url = (string)$references[0]->getPublicUrl();

        return $url !== '' ? $url : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findDetailContentElement(int $pageId): ?array
    {
        return $this->findFirstContentElementByType($pageId, 'mpc_vidply_detail');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findAllContentElementsByType(int $pageId, string $cType): array
    {
        if ($pageId <= 0) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $rows = $qb
            ->select('*')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pageId, Connection::PARAM_INT)),
                $qb->expr()->eq('CType', $qb->createNamedParameter($cType))
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_filter($rows, static fn (array $row): bool => $row !== []));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findFirstContentElementByType(int $pageId, string $cType): ?array
    {
        $rows = $this->findAllContentElementsByType($pageId, $cType);

        return $rows[0] ?? null;
    }

    private function buildDetailUrl(
        ContentObjectRenderer $cObj,
        int $pageUid,
        int $mediaUid,
        string $slug,
        int $languageId
    ): string {
        if ($pageUid <= 0 || $mediaUid <= 0) {
            return '';
        }

        $config = [
            'parameter' => $pageUid,
            'additionalParams' => '&media=' . rawurlencode((string)$mediaUid),
            'forceAbsoluteUrl' => true,
            'returnLast' => 'url',
        ];
        if ($languageId > 0) {
            $config['language'] = $languageId;
        }

        try {
            $url = (string)$cObj->typoLink_URL($config);
        } catch (\Throwable) {
            $url = '';
        }

        if ($url !== '') {
            return $url;
        }

        if ($slug !== '') {
            return $this->makeAbsoluteUrl($cObj, '/' . ltrim($slug, '/'));
        }

        return $this->makeAbsoluteUrl($cObj, '?media=' . $mediaUid);
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

    private function makeAbsoluteUrl(ContentObjectRenderer $cObj, string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $normalizedParams = $cObj->getRequest()->getAttribute('normalizedParams');
        if ($normalizedParams === null) {
            return $url;
        }

        return rtrim($normalizedParams->getSiteUrl(), '/') . '/' . ltrim($url, '/');
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
