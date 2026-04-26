<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\DataProcessing;

use Mpc\MpcVidply\Repository\MediaRepository;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

/**
 * DataProcessor for the VidPly Listview content element.
 *
 * Resolves the content element's child `tx_mpcvidply_listview_row` records and, for each row,
 * builds a lightweight "card" list for the template. Each card carries enough information to
 * render a poster/title/duration tile that links to the detail page via a route-enhanced URL.
 */
final class ListviewProcessor implements DataProcessorInterface
{
    private readonly ConnectionPool $connectionPool;
    private readonly MediaRepository $mediaRepository;
    private readonly ResourceFactory $resourceFactory;

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?MediaRepository $mediaRepository = null,
        ?ResourceFactory $resourceFactory = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->mediaRepository = $mediaRepository ?? GeneralUtility::makeInstance(MediaRepository::class);
        $this->resourceFactory = $resourceFactory ?? GeneralUtility::makeInstance(ResourceFactory::class);
    }

    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ): array {
        $data = $processedData['data'];
        $request = $cObj->getRequest();
        $languageId = $this->resolveLanguageId($request, $data);

        $contentUid = (int)($data['uid'] ?? 0);
        // `tt_content` uses `l18n_parent` in the database; `l10n_parent` is a legacy alias in some rows.
        $l10nParent = (int)($data['l18n_parent'] ?? $data['l10n_parent'] ?? 0);

        $detailPageUid = (int)($data['tx_mpcvidply_detail_page'] ?? 0);

        $rows = $this->fetchRowsForContent($contentUid, $l10nParent, $languageId);

        $processedData['listview'] = [
            'uid' => $contentUid,
            'detailPageUid' => $detailPageUid,
            'rows' => $this->assembleRows($rows, $cObj, $languageId, $detailPageUid),
        ];

        return $processedData;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assembleRows(array $rows, ContentObjectRenderer $cObj, int $languageId, int $detailPageUid): array
    {
        $out = [];
        foreach ($rows as $row) {
            $rowUid = (int)($row['uid'] ?? 0);
            if ($rowUid <= 0) {
                continue;
            }

            $selectionMode = (string)($row['selection_mode'] ?? 'manual');
            $limit = max(1, (int)($row['limit_items'] ?? 12));
            $sortBy = (string)($row['sort_by'] ?? 'sorting');

            if ($selectionMode === 'category') {
                $categoryUids = $this->resolveCategoryUidsForRow($rowUid);
                $mediaRecords = $this->mediaRepository->findByCategories($categoryUids, $languageId, $limit, $sortBy);
            } else {
                $mediaRecords = $this->mediaRepository->findByRowUid($rowUid, $languageId);
                if ($sortBy === 'title_asc') {
                    usort(
                        $mediaRecords,
                        static fn(array $a, array $b): int => strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''))
                    );
                } elseif ($sortBy === 'crdate_desc') {
                    usort(
                        $mediaRecords,
                        static fn(array $a, array $b): int => (int)($b['crdate'] ?? 0) <=> (int)($a['crdate'] ?? 0)
                    );
                }
                $mediaRecords = array_slice($mediaRecords, 0, $limit);
            }

            $mediaUids = array_values(array_filter(
                array_map(static fn(array $m): int => (int)($m['uid'] ?? 0), $mediaRecords),
                static fn(int $uid): bool => $uid > 0
            ));
            $posterRefsByMediaUid = $this->prefetchPosterReferences($mediaUids);
            $categoryMap = $this->fetchCategoriesByForeignMediaUids(
                $this->collectCategoryRelationMediaUids($mediaRecords),
                $languageId
            );

            $cards = [];
            foreach ($mediaRecords as $media) {
                $card = $this->buildCardData(
                    $media,
                    $posterRefsByMediaUid,
                    $categoryMap,
                    $cObj,
                    $detailPageUid,
                    $languageId
                );
                if ($card !== null) {
                    $cards[] = $card;
                }
            }

            $layout = (string)($row['layout'] ?? 'shelf');
            if (!in_array($layout, ['grid', 'shelf'], true)) {
                $layout = 'shelf';
            }

            $cardStyle = (string)($row['card_style'] ?? 'poster');
            if (!in_array($cardStyle, ['poster', 'poster_compact', 'landscape'], true)) {
                $cardStyle = 'poster';
            }

            $cardsCount = count($cards);
            $perPage = max(1, min(200, (int)($row['pagination_per_page'] ?? 12)));
            $paginationAllowed = $layout === 'grid' && (int)($row['enable_pagination'] ?? 1) === 1;
            $paginationActive = $paginationAllowed && $cardsCount > $perPage;
            $paginationTotalPages = $paginationActive
                ? max(1, (int)ceil($cardsCount / $perPage))
                : 0;

            $out[] = [
                'uid' => $rowUid,
                'headline' => (string)($row['headline'] ?? ''),
                'headlineLink' => $this->resolveHeadlineLink((string)($row['headline_link'] ?? ''), $cObj),
                'layout' => $layout,
                'cardStyle' => $cardStyle,
                'selectionMode' => $selectionMode,
                'sortBy' => $sortBy,
                'cards' => $cards,
                'cardsCount' => $cardsCount,
                'paginationEnabled' => $paginationAllowed,
                'paginationPerPage' => $perPage,
                'paginationActive' => $paginationActive,
                'paginationTotalPages' => $paginationTotalPages,
                'domId' => 'mpc-vidply-row-' . $rowUid,
            ];
        }
        return $out;
    }

    /**
     * @param array<int, FileReference[]> $posterRefsByMediaUid
     * @param array<int, list<array{uid: int, title: string}>> $categoryMap Indexed by
     *        `tx_mpcvidply_media` uid (relation `sys_category_record_mm.uid_foreign`).
     */
    private function buildCardData(
        array $media,
        array $posterRefsByMediaUid,
        array $categoryMap,
        ContentObjectRenderer $cObj,
        int $detailPageUid,
        int $languageId
    ): ?array {
        $uid = (int)($media['uid'] ?? 0);
        if ($uid <= 0) {
            return null;
        }

        $defaultUid = (int)($media['l10n_parent'] ?? 0);
        if ($defaultUid <= 0) {
            $defaultUid = $uid;
        }

        $posterRefs = $posterRefsByMediaUid[$uid] ?? $posterRefsByMediaUid[$defaultUid] ?? [];
        [$posterReferenceUid, $posterUrl, $posterAlt] = $this->resolvePosterData($posterRefs, (string)($media['title'] ?? ''));

        $slug = trim((string)($media['slug'] ?? ''));
        $detailUrl = $this->buildDetailUrl($cObj, $detailPageUid, $defaultUid, $slug, $languageId);

        $mediaType = (string)($media['media_type'] ?? 'video');
        $isExternal = in_array($mediaType, ['youtube', 'vimeo', 'soundcloud'], true);
        $categories = $this->resolveCategoriesForMedia($media, $categoryMap);

        return [
            'uid' => $uid,
            'title' => (string)($media['title'] ?? ''),
            'crdate' => (int)($media['crdate'] ?? 0),
            'description' => (string)($media['description'] ?? ''),
            'artist' => (string)($media['artist'] ?? ''),
            'duration' => (int)($media['duration'] ?? 0),
            'durationFormatted' => $this->formatDuration((int)($media['duration'] ?? 0)),
            'slug' => $slug,
            'mediaType' => $mediaType,
            'posterReferenceUid' => $posterReferenceUid,
            'poster' => $posterUrl,
            'posterAlt' => $posterAlt,
            'detailUrl' => $detailUrl,
            'isExternal' => $isExternal,
            'categories' => $categories,
        ];
    }

    /**
     * @param list<array<string, mixed>> $mediaRecords
     * @return list<int>
     */
    private function collectCategoryRelationMediaUids(array $mediaRecords): array
    {
        $ids = [];
        foreach ($mediaRecords as $m) {
            $fk = $this->resolveCategoryMmForeignMediaUid($m);
            if ($fk > 0) {
                $ids[] = $fk;
            }
            $uid = (int)($m['uid'] ?? 0);
            if ((int)($m['l10n_parent'] ?? 0) > 0 && $uid > 0) {
                $ids[] = $uid;
            }
        }
        return array_values(array_unique($ids, SORT_NUMERIC));
    }

    private function resolveCategoryMmForeignMediaUid(array $media): int
    {
        $l10nParent = (int)($media['l10n_parent'] ?? 0);
        $uid = (int)($media['uid'] ?? 0);
        return $l10nParent > 0 ? $l10nParent : $uid;
    }

    /**
     * @param array<int, list<array{uid: int, title: string}>> $categoryMap
     * @return list<array{uid: int, title: string}>
     */
    private function resolveCategoriesForMedia(array $media, array $categoryMap): array
    {
        $fk = $this->resolveCategoryMmForeignMediaUid($media);
        $list = $categoryMap[$fk] ?? [];
        if ($list === [] && (int)($media['l10n_parent'] ?? 0) > 0) {
            $list = $categoryMap[(int)($media['uid'] ?? 0)] ?? [];
        }
        $list = array_values(
            array_filter(
                $list,
                static fn(array $c): bool => trim((string)($c['title'] ?? '')) !== ''
            )
        );
        return $list;
    }

    /**
     * @param list<int> $foreignMediaUids `sys_category_record_mm.uid_foreign` targets
     * @return array<int, list<array{uid: int, title: string}>>
     */
    private function fetchCategoriesByForeignMediaUids(array $foreignMediaUids, int $languageId): array
    {
        $foreignMediaUids = array_values(array_filter(
            array_map(static fn(int|string $v): int => (int)$v, $foreignMediaUids),
            static fn(int $u): bool => $u > 0
        ));
        if ($foreignMediaUids === []) {
            return [];
        }

        $lang = array_values(array_unique([0, -1, $languageId], SORT_REGULAR));

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_category');
        $rows = $qb
            ->select('mm.uid_foreign', 'sys_category.uid', 'sys_category.title', 'mm.sorting')
            ->from('sys_category')
            ->join(
                'sys_category',
                'sys_category_record_mm',
                'mm',
                (string)$qb->expr()->and(
                    $qb->expr()->eq('mm.uid_local', $qb->quoteIdentifier('sys_category.uid')),
                    $qb->expr()->eq('mm.tablenames', $qb->createNamedParameter('tx_mpcvidply_media')),
                    $qb->expr()->eq('mm.fieldname', $qb->createNamedParameter('categories')),
                    $qb->expr()->in('mm.uid_foreign', $qb->createNamedParameter($foreignMediaUids, Connection::PARAM_INT_ARRAY))
                )
            )
            ->where(
                $qb->expr()->eq('sys_category.deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('sys_category.hidden', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->in(
                    'sys_category.sys_language_uid',
                    $qb->createNamedParameter($lang, Connection::PARAM_INT_ARRAY)
                )
            )
            ->orderBy('mm.uid_foreign', 'ASC')
            ->addOrderBy('mm.sorting', 'ASC')
            ->addOrderBy('sys_category.title', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        /** @var array<int, list<array{uid: int, title: string}>> $out */
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $foreign = (int)($row['uid_foreign'] ?? 0);
            $cUid = (int)($row['uid'] ?? 0);
            if ($foreign <= 0 || $cUid <= 0) {
                continue;
            }
            $title = (string)($row['title'] ?? '');
            $k = $foreign . '-' . $cUid;
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[$foreign] ??= [];
            $out[$foreign][] = ['uid' => $cUid, 'title' => $title];
        }
        return $out;
    }

    /**
     * Resolves the first available poster FileReference into the data needed by the template.
     *
     * Returns `[sys_file_reference.uid|null, publicUrl|null, alt]`. The reference UID is used
     * by `<f:image treatIdAsReference="1">` so the template can crop to the card's aspect
     * ratio and generate responsive WebP variants; the `publicUrl` is kept as a pragmatic
     * fallback (e.g. for og:image in the detail template).
     *
     * @param FileReference[] $posterRefs
     * @return array{0:?int,1:?string,2:string}
     */
    private function resolvePosterData(array $posterRefs, string $title): array
    {
        if ($posterRefs === []) {
            return [null, null, $title];
        }
        $reference = $posterRefs[0];

        $referenceUid = (int)$reference->getUid();
        if ($referenceUid <= 0) {
            return [null, null, $title];
        }

        $publicUrl = (string)$reference->getPublicUrl();
        $alt = trim((string)$reference->getAlternative()) !== ''
            ? (string)$reference->getAlternative()
            : $title;

        return [$referenceUid, $publicUrl !== '' ? $publicUrl : null, $alt];
    }

    /**
     * Build the detail-page URL for one media record.
     *
     * The generated URL always carries `media=<uid>`. TYPO3's VidPlyDetail route
     * enhancer ({@see Configuration/Sets/mpc-vidply/route-enhancers.yaml}) then
     * rewrites the parameter to the speaking slug segment. If the record has no
     * slug, {@see \Mpc\MpcVidply\Routing\Aspect\VidPlyMediaRouteAspect} makes the
     * enhancer step down so the PageRouter returns `?media=<uid>&cHash=…` (no
     * unreliable `&id=<pageId>`). We do not build the query string manually.
     */
    private function buildDetailUrl(
        ContentObjectRenderer $cObj,
        int $detailPageUid,
        int $mediaUid,
        string $slug,
        int $languageId
    ): string {
        if ($detailPageUid <= 0 || $mediaUid <= 0) {
            return '';
        }

        $config = [
            'parameter' => $detailPageUid,
            'queryParameters' => [
                'media' => (string)$mediaUid,
            ],
            'forceAbsoluteUrl' => 0,
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

        // Last-resort: no `id`, site routing removes it; relative query is enough on same host.
        if ($url === '') {
            if ($slug !== '') {
                return '/' . ltrim($slug, '/');
            }
            return '?media=' . $mediaUid;
        }

        return $url;
    }

    private function resolveHeadlineLink(string $value, ContentObjectRenderer $cObj): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        try {
            return (string)$cObj->typoLink_URL(['parameter' => $value]);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Load listview child rows for `parentid` = `tt_content.uid`.
     *
     * For a translated listview element (`l18n_parent` > 0), rows must follow
     * the default-language content element, same as "Value of default language" for
     * the playlist. Querying both current and source and **preferring** the current
     * CE when it had *any* rows caused empty placeholder rows on the translation to
     * override fully configured shelves on the default CE (0 items on FE).
     *
     * We therefore load by **translation source uid only** when it is set, and
     * fall back to the current CE if no rows exist (legacy data attached only to the
     * translation).
     *
     * @return list<array<string, mixed>>
     */
    private function fetchRowsForContent(int $contentUid, int $translationSourceUid, int $languageId): array
    {
        if ($contentUid <= 0) {
            return [];
        }

        $parentIds = $translationSourceUid > 0 ? [$translationSourceUid] : [$contentUid];

        $rows = $this->queryListviewRowsByParentIds($parentIds, $languageId);
        if ($rows === [] && $translationSourceUid > 0) {
            $rows = $this->queryListviewRowsByParentIds([$contentUid], $languageId);
        }

        if ($languageId <= 0) {
            return array_values(array_filter(
                $rows,
                static fn(array $row): bool => (int)($row['sys_language_uid'] ?? 0) <= 0
            ));
        }

        // Language overlay: prefer translated row, fall back to default row.
        $byDefaultUid = [];
        foreach ($rows as $row) {
            $language = (int)($row['sys_language_uid'] ?? 0);
            $uid = (int)($row['uid'] ?? 0);
            $parent = (int)($row['l10n_parent'] ?? 0);
            if ($language === 0 || $language === -1) {
                $byDefaultUid[$uid] = $row;
                continue;
            }
            if ($language === $languageId && $parent > 0) {
                $byDefaultUid[$parent] = $row;
            }
        }
        return array_values($byDefaultUid);
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

        return array_values(array_unique(array_map(static fn(int|string $v): int => (int)$v, $uids)));
    }

    /**
     * @param list<int> $mediaUids
     * @return array<int, FileReference[]>
     */
    private function prefetchPosterReferences(array $mediaUids): array
    {
        if ($mediaUids === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $rows = $qb
            ->select('uid', 'uid_foreign')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tx_mpcvidply_media')),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter('poster')),
                $qb->expr()->in(
                    'uid_foreign',
                    $qb->createNamedParameter($mediaUids, Connection::PARAM_INT_ARRAY)
                ),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('uid_foreign', 'ASC')
            ->addOrderBy('sorting_foreign', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $fileRefUid = (int)($row['uid'] ?? 0);
            $mediaUid = (int)($row['uid_foreign'] ?? 0);
            if ($fileRefUid <= 0 || $mediaUid <= 0) {
                continue;
            }
            try {
                $ref = $this->resourceFactory->getFileReferenceObject($fileRefUid);
            } catch (ResourceDoesNotExistException) {
                continue;
            }
            $result[$mediaUid] ??= [];
            $result[$mediaUid][] = $ref;
        }
        return $result;
    }

    private function resolveLanguageId(ServerRequestInterface $request, array $data): int
    {
        $language = $request->getAttribute('language');
        if ($language !== null) {
            $id = (int)($language->toArray()['languageId'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }
        return (int)($data['sys_language_uid'] ?? 0);
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '';
        }
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }
        return sprintf('%d:%02d', $minutes, $secs);
    }
}
