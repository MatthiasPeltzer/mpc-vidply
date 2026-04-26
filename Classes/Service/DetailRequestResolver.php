<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use Mpc\MpcVidply\Repository\MediaRepository;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the VidPly media record that belongs to the current frontend request.
 *
 * Used by both the page title provider and the breadcrumb override processor so
 * they agree on whether the current request is a "detail view" and which record
 * drives the override.
 *
 * The resolver guards the override in two ways:
 *   1. A `media` query parameter must be present (set either from the Simple
 *      route enhancer slug or from the explicit `?media=<uid>` fallback).
 *   2. The current page must host at least one `mpc_vidply_detail` content
 *      element. Without this check, any page receiving a rogue `?media=…`
 *      parameter would get its breadcrumb/title rewritten.
 */
final class DetailRequestResolver
{
    private readonly MediaRepository $mediaRepository;
    private readonly ConnectionPool $connectionPool;

    /**
     * Per-request memoization keyed by "pageId|languageId|mediaParam".
     *
     * @var array<string, array<string, mixed>|null>
     */
    private array $cache = [];

    /**
     * @var array<int, bool>
     */
    private array $detailPageCache = [];

    public function __construct(
        ?MediaRepository $mediaRepository = null,
        ?ConnectionPool $connectionPool = null
    ) {
        $this->mediaRepository = $mediaRepository ?? GeneralUtility::makeInstance(MediaRepository::class);
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveFromRequest(ServerRequestInterface $request): ?array
    {
        $mediaParam = $request->getQueryParams()['media'] ?? null;
        if (!is_string($mediaParam) && !is_int($mediaParam)) {
            return null;
        }
        $mediaParam = trim((string)$mediaParam);
        if ($mediaParam === '') {
            return null;
        }

        $pageId = $this->resolvePageId($request);
        if ($pageId <= 0) {
            return null;
        }

        if (!$this->pageHostsDetailCe($pageId)) {
            return null;
        }

        $languageId = $this->resolveLanguageId($request);
        $cacheKey = $pageId . '|' . $languageId . '|' . $mediaParam;
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $media = is_numeric($mediaParam)
            ? $this->mediaRepository->findByUid((int)$mediaParam, $languageId)
            : $this->mediaRepository->findBySlug($mediaParam, $languageId);

        return $this->cache[$cacheKey] = $media;
    }

    private function pageHostsDetailCe(int $pageId): bool
    {
        if (isset($this->detailPageCache[$pageId])) {
            return $this->detailPageCache[$pageId];
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
            $uid = $qb
                ->select('uid')
                ->from('tt_content')
                ->where(
                    $qb->expr()->eq('pid', $qb->createNamedParameter($pageId, Connection::PARAM_INT)),
                    $qb->expr()->eq('CType', $qb->createNamedParameter('mpc_vidply_detail'))
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        } catch (\Throwable) {
            return $this->detailPageCache[$pageId] = false;
        }

        return $this->detailPageCache[$pageId] = ($uid !== false && (int)$uid > 0);
    }

    private function resolvePageId(ServerRequestInterface $request): int
    {
        $pageInformation = $request->getAttribute('frontend.page.information');
        if ($pageInformation !== null && method_exists($pageInformation, 'getId')) {
            return (int)$pageInformation->getId();
        }

        $routing = $request->getAttribute('routing');
        if ($routing !== null && method_exists($routing, 'getPageId')) {
            return (int)$routing->getPageId();
        }

        return 0;
    }

    private function resolveLanguageId(ServerRequestInterface $request): int
    {
        $language = $request->getAttribute('language');
        if ($language !== null && method_exists($language, 'getLanguageId')) {
            return (int)$language->getLanguageId();
        }
        return 0;
    }
}
