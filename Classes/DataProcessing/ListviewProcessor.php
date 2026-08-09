<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\DataProcessing;

use Mpc\MpcVidply\Service\DetailUrlBuilder;
use Mpc\MpcVidply\Service\DurationFormatter;
use Mpc\MpcVidply\Service\FileReferencePrefetcher;
use Mpc\MpcVidply\Service\FrontendLanguageResolver;
use Mpc\MpcVidply\Service\ListviewMediaResolver;
use Mpc\MpcVidply\Service\MediaCategoryResolver;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

/**
 * DataProcessor for the VidPly Listview content element.
 *
 * Resolves the content element's child `tx_mpcvidply_listview_row` records and, for each row,
 * builds a lightweight "card" list for the template. Each card carries enough information to
 * render a poster/title/duration tile that links to the detail page via a route-enhanced URL.
 *
 * Row/media resolution is delegated to {@see ListviewMediaResolver} so the structured-data
 * builder ({@see \Mpc\MpcVidply\Service\VidPlyPageMediaResolver}) derives the same records.
 */
final class ListviewProcessor implements DataProcessorInterface
{
    private readonly ConnectionPool $connectionPool;
    private readonly ListviewMediaResolver $mediaResolver;
    private readonly MediaCategoryResolver $mediaCategoryResolver;
    private readonly FileReferencePrefetcher $fileReferencePrefetcher;
    private readonly DetailUrlBuilder $detailUrlBuilder;
    private readonly DurationFormatter $durationFormatter;

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?ListviewMediaResolver $mediaResolver = null,
        ?MediaCategoryResolver $mediaCategoryResolver = null,
        ?FileReferencePrefetcher $fileReferencePrefetcher = null,
        ?DetailUrlBuilder $detailUrlBuilder = null,
        ?DurationFormatter $durationFormatter = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->mediaResolver = $mediaResolver ?? GeneralUtility::makeInstance(ListviewMediaResolver::class);
        $this->mediaCategoryResolver = $mediaCategoryResolver ?? GeneralUtility::makeInstance(MediaCategoryResolver::class);
        $this->fileReferencePrefetcher = $fileReferencePrefetcher
            ?? GeneralUtility::makeInstance(FileReferencePrefetcher::class);
        $this->detailUrlBuilder = $detailUrlBuilder ?? GeneralUtility::makeInstance(DetailUrlBuilder::class);
        $this->durationFormatter = $durationFormatter ?? GeneralUtility::makeInstance(DurationFormatter::class);
    }

    /**
     * @param array<string, mixed> $contentObjectConfiguration
     * @param array<string, mixed> $processorConfiguration
     * @param array<string, mixed> $processedData
     * @return array<string, mixed>
     */
    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ): array {
        $data = $processedData['data'];
        $request = $cObj->getRequest();
        $languageId = FrontendLanguageResolver::resolveLanguageId($request, $data);

        $contentUid = (int)($data['uid'] ?? 0);
        // `tt_content` uses `l18n_parent` in the database; `l10n_parent` is a legacy alias in some rows.
        $l10nParent = (int)($data['l18n_parent'] ?? $data['l10n_parent'] ?? 0);
        if ($l10nParent <= 0 && $languageId > 0 && $contentUid > 0) {
            $l10nParent = $this->resolveTranslationSourceUid($contentUid);
        }

        $detailPageUid = (int)($data['tx_mpcvidply_detail_page'] ?? 0);

        $rows = $this->mediaResolver->resolveRows($contentUid, $l10nParent, $languageId);

        $processedData['listview'] = [
            'uid' => $contentUid,
            'detailPageUid' => $detailPageUid,
            'rows' => $this->assembleRows($rows, $cObj, $languageId, $detailPageUid),
        ];

        return $processedData;
    }

    private function resolveTranslationSourceUid(int $contentUid): int
    {
        if ($contentUid <= 0) {
            return 0;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $parent = $qb
            ->select('l18n_parent')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($contentUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($parent) ? (int)$parent : 0;
    }

    /**
     * @param list<array<string, mixed>> $rows
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
            $sortBy = (string)($row['sort_by'] ?? 'sorting');

            $mediaRecords = $this->mediaResolver->resolveMediaRecordsForRow($row, $languageId);

            $mediaUids = array_values(array_filter(
                array_map(static fn (array $m): int => (int)($m['uid'] ?? 0), $mediaRecords),
                static fn (int $uid): bool => $uid > 0
            ));
            $posterRefsByMediaUid = $this->fileReferencePrefetcher->prefetchField($mediaUids, 'poster');
            $categoryMap = $this->mediaCategoryResolver->fetchForMediaRecords($mediaRecords, $languageId);

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
     * @param array<string, mixed> $media
     * @param array<int, FileReference[]> $posterRefsByMediaUid
     * @param array<int, list<array{uid: int, title: string}>> $categoryMap Indexed by
     *        `tx_mpcvidply_media` uid (relation `sys_category_record_mm.uid_foreign`).
     * @return array<string, mixed>|null
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
        $detailUrl = $this->detailUrlBuilder->build($cObj, $detailPageUid, $defaultUid, $slug, $languageId);

        $mediaType = (string)($media['media_type'] ?? 'video');
        $isExternal = in_array($mediaType, ['youtube', 'vimeo', 'soundcloud'], true);
        $categories = $this->mediaCategoryResolver->resolveForMedia($media, $categoryMap);

        return [
            'uid' => $uid,
            'title' => (string)($media['title'] ?? ''),
            'crdate' => (int)($media['crdate'] ?? 0),
            'description' => (string)($media['description'] ?? ''),
            'longDescription' => (string)($media['long_description'] ?? ''),
            'artist' => (string)($media['artist'] ?? ''),
            'duration' => (int)($media['duration'] ?? 0),
            'durationFormatted' => $this->durationFormatter->format((int)($media['duration'] ?? 0)),
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

}
