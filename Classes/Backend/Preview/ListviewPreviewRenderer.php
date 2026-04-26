<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Backend\Preview;

use Mpc\MpcVidply\Repository\MediaRepository;
use TYPO3\CMS\Backend\Preview\StandardContentPreviewRenderer;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridColumnItem;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ListviewPreviewRenderer extends StandardContentPreviewRenderer
{
    private const LLL = 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:';

    private const PREVIEW_MAX_ITEMS_PER_ROW = 36;

    private readonly ConnectionPool $connectionPool;
    private readonly ResourceFactory $resourceFactory;
    private readonly MediaRepository $mediaRepository;

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?ResourceFactory $resourceFactory = null,
        ?MediaRepository $mediaRepository = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->resourceFactory = $resourceFactory ?? GeneralUtility::makeInstance(ResourceFactory::class);
        $this->mediaRepository = $mediaRepository ?? GeneralUtility::makeInstance(MediaRepository::class);
    }

    public function renderPageModulePreviewContent(GridColumnItem $item): string
    {
        $record = $item->getRecord();
        if (is_object($record) && method_exists($record, 'toArray')) {
            $record = $record->toArray();
        }

        // The grid / Record payload can omit l18n_parent. Without it we only
        // query parentid = uid and miss rows that hang off the translation
        // source, so the preview falsely shows "no rows". Always merge DB.
        // `tt_content` uses `l18n_parent` (not `l10n_parent`) as DB column.
        $contentUid = (int)($record['uid'] ?? 0);
        if ($contentUid > 0) {
            $row = BackendUtility::getRecord(
                'tt_content',
                $contentUid,
                ['uid', 'l18n_parent', 'sys_language_uid']
            );
            if (is_array($row)) {
                $record = array_replace($record, $row);
            }
        }
        $l10nParent = (int)($record['l18n_parent'] ?? $record['l10n_parent'] ?? 0);
        $ceLanguage = (int)($record['sys_language_uid'] ?? 0);

        $rows = $this->fetchRows($contentUid, $l10nParent);
        $lang = $this->getLanguageService();

        $html = '<div class="mpc-vidply-listview-preview w-100 mt-2">';

        if ($rows === []) {
            $warning = htmlspecialchars($lang->sL(self::LLL . 'preview.listview.empty') ?: 'No listview rows configured yet');
            $hint = htmlspecialchars($lang->sL(self::LLL . 'preview.listview.empty.hint') ?: 'Add at least one row to this listview element');
            $html .= '<div class="callout callout-warning"><div class="callout-body">';
            $html .= '<strong>' . $warning . '</strong><br><small class="text-muted">' . $hint . '</small>';
            $html .= '</div></div>';
        } else {
            $summaryLabel = sprintf(
                $lang->sL(self::LLL . 'preview.listview.summary') ?: '%d listview row(s) configured',
                count($rows)
            );
            $html .= '<div class="d-block w-100 small fw-bold mb-2">';
            $html .= htmlspecialchars($summaryLabel);
            $html .= '</div>';

            $layoutShelf = $lang->sL(self::LLL . 'preview.listview.layout.shelf') ?: 'Shelf';
            $layoutGrid = $lang->sL(self::LLL . 'preview.listview.layout.grid') ?: 'Grid';
            $selManual = $lang->sL(self::LLL . 'preview.listview.selection.manual') ?: 'Manual';
            $selCategory = $lang->sL(self::LLL . 'preview.listview.selection.category') ?: 'By category';

            foreach ($rows as $row) {
                $headline = trim((string)($row['headline'] ?? ''));
                $layout = (string)($row['layout'] ?? 'shelf');
                $selectionMode = (string)($row['selection_mode'] ?? 'manual');
                $rowUid = (int)($row['uid'] ?? 0);
                $itemCount = $this->countItems($rowUid, $selectionMode);
                $mediaRecords = $this->loadMediaForListviewRow($row, $ceLanguage);

                $html .= '<div class="callout callout-default mpc-vidply-listview-row w-100 my-1 p-2">';
                $html .= '<div class="container-fluid px-0">';
                $html .= '<div class="row g-0 gy-2">';

                $html .= '<div class="col-12">';
                $html .= '<div class="mpc-vidply-listview-row__headline w-100">';
                $html .= '<strong>' . htmlspecialchars($headline !== '' ? $headline : 'Untitled row') . '</strong>';
                $html .= '</div></div>';

                $html .= '<div class="col-12">';
                $html .= '<div class="mpc-vidply-listview-row__media w-100">';
                $html .= $this->renderListviewRowMediaTiles($mediaRecords, $lang);
                $html .= '</div></div>';

                $html .= '<div class="col-12">';
                $html .= '<div class="mpc-vidply-listview-row__meta w-100 lh-sm">';
                $html .= '<span class="badge text-bg-info">'
                    . htmlspecialchars($layout === 'grid' ? $layoutGrid : $layoutShelf)
                    . '</span>';
                $html .= ' <span class="badge text-bg-secondary ms-1">'
                    . htmlspecialchars($selectionMode === 'category' ? $selCategory : $selManual)
                    . '</span>';
                $html .= ' <span class="text-muted small ms-1">('
                    . (int)$itemCount . ' items)</span>';
                $html .= $this->renderListviewRowPreviewHints($row, $mediaRecords, $lang);
                $html .= '</div></div>';

                $html .= '</div></div></div>';
            }
        }

        $detailPage = $this->resolveDetailPageUid($record['tx_mpcvidply_detail_page'] ?? null);
        if ($detailPage > 0) {
            $detailLabel = sprintf(
                $lang->sL(self::LLL . 'preview.listview.detail_page') ?: 'Detail page: PID %d',
                $detailPage
            );
            $html .= '<p class="small text-muted w-100 mb-0 mt-2">'
                . htmlspecialchars($detailLabel)
                . '</p>';
        } else {
            $warning = htmlspecialchars(
                $lang->sL(self::LLL . 'preview.listview.detail_page.missing')
                    ?: 'No detail page selected — card links will not be generated'
            );
            $html .= '<div class="callout callout-warning w-100 mt-2"><div class="callout-body">'
                . '<small class="text-body-secondary">' . $warning . '</small></div></div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Same parent resolution as {@see \Mpc\MpcVidply\DataProcessing\ListviewProcessor::fetchRowsForContent()}.
     *
     * The page-module preview does not filter `sys_language_uid`: child rows
     * for overlays or "all languages" would otherwise be omitted when the
     * in-memory record was incomplete. `hidden=0` matches the FE listview.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchRows(int $contentUid, int $translationSourceUid): array
    {
        if ($contentUid <= 0) {
            return [];
        }
        $parentIds = $translationSourceUid > 0 ? [$translationSourceUid] : [$contentUid];
        $rows = $this->queryListviewRowRecordsForPreview($parentIds);
        if ($rows === [] && $translationSourceUid > 0) {
            $rows = $this->queryListviewRowRecordsForPreview([$contentUid]);
        }
        return $rows;
    }

    /**
     * @param list<int> $parentIds
     * @return list<array<string, mixed>>
     */
    private function queryListviewRowRecordsForPreview(array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_listview_row');
        return $qb
            ->select('uid', 'headline', 'layout', 'selection_mode', 'limit_items', 'parentid', 'sort_by')
            ->from('tx_mpcvidply_listview_row')
            ->where(
                $qb->expr()->in('parentid', $qb->createNamedParameter($parentIds, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('parenttable', $qb->createNamedParameter('tt_content')),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function countItems(int $rowUid, string $selectionMode): int
    {
        if ($rowUid <= 0) {
            return 0;
        }
        if ($selectionMode === 'category') {
            $qb = $this->connectionPool->getQueryBuilderForTable('sys_category_record_mm');
            $count = $qb
                ->count('uid_local')
                ->from('sys_category_record_mm')
                ->where(
                    $qb->expr()->eq('tablenames', $qb->createNamedParameter('tx_mpcvidply_listview_row')),
                    $qb->expr()->eq('fieldname', $qb->createNamedParameter('categories')),
                    $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($rowUid, Connection::PARAM_INT))
                )
                ->executeQuery()
                ->fetchOne();
            return (int)$count;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_listview_row_media_mm');
        $count = $qb
            ->count('uid_foreign')
            ->from('tx_mpcvidply_listview_row_media_mm')
            ->where(
                $qb->expr()->eq('uid_local', $qb->createNamedParameter($rowUid, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne();
        return (int)$count;
    }

    /**
     * @param list<array<string, mixed>> $mediaRecords
     */
    private function renderListviewRowMediaTiles(array $mediaRecords, LanguageService $lang): string
    {
        if ($mediaRecords === []) {
            $empty = $lang->sL(self::LLL . 'preview.listview.row_items_empty') ?: 'No items in this row';

            return '<div class="row g-0 mx-0 w-100"><div class="col-12 small text-muted">'
                . htmlspecialchars($empty) . '</div></div>';
        }

        $mediaUids = array_values(array_filter(
            array_map(static fn(array $m): int => (int)($m['uid'] ?? 0), $mediaRecords),
            static fn(int $u): bool => $u > 0
        ));
        $posterRefsByMediaUid = $this->prefetchPosterReferences($mediaUids);

        $html = '<div class="mpc-vidply-listview-row-items row g-2 mx-0 w-100 align-items-start justify-content-start">';
        foreach ($mediaRecords as $media) {
            $uid = (int)($media['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $defaultUid = (int)($media['l10n_parent'] ?? 0);
            if ($defaultUid <= 0) {
                $defaultUid = $uid;
            }
            $refs = $posterRefsByMediaUid[$uid] ?? $posterRefsByMediaUid[$defaultUid] ?? [];
            $title = (string)($media['title'] ?? '');
            $alt = $title;
            if ($refs !== []) {
                $alt0 = trim((string)$refs[0]->getAlternative());
                if ($alt0 !== '') {
                    $alt = $alt0;
                }
            }
            $imgUrl = $this->resolveFirstPosterImageUrl($refs);
            $html .= '<div class="col-auto">';
            $html .= '<div class="mpc-vidply-listview-tile text-center" style="width:6.25rem;max-width:100%;">';
            if ($imgUrl !== null && $imgUrl !== '') {
                $html .= '<div class="w-100 rounded-1 overflow-hidden bg-light" style="height:3.5rem;">';
                $html .= '<img class="w-100 h-100 object-fit-cover" src="' . htmlspecialchars($imgUrl) . '" alt="'
                    . htmlspecialchars($alt) . '" loading="lazy" width="100" height="56" />';
                $html .= '</div>';
            } else {
                if ($title === '') {
                    $initial = '—';
                } else {
                    $initial = function_exists('mb_substr') ? mb_substr($title, 0, 1) : substr($title, 0, 1);
                }
                $html .= '<div class="d-flex w-100 align-items-center justify-content-center small text-muted'
                    . ' bg-light rounded-1" style="height:3.5rem;">'
                    . htmlspecialchars($initial) . '</div>';
            }
            $html .= '<div class="w-100 small text-break mt-1 lh-sm">'
                . htmlspecialchars($title !== '' ? $title : ('#' . (string)$uid)) . '</div>';
            $html .= '</div></div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Truncation / cap notes placed after the media area with the other row meta.
     *
     * @param list<array<string, mixed>> $mediaRecords
     */
    private function renderListviewRowPreviewHints(array $row, array $mediaRecords, LanguageService $lang): string
    {
        $rowUid = (int)($row['uid'] ?? 0);
        $selectionMode = (string)($row['selection_mode'] ?? 'manual');
        $html = '';
        if ($selectionMode === 'manual') {
            $mmTotal = $rowUid > 0 ? $this->countItems($rowUid, 'manual') : 0;
            if ($mmTotal > count($mediaRecords)) {
                $more = $lang->sL(self::LLL . 'preview.listview.row_items_truncated') ?: '%1$d of %2$d items shown';
                $html .= '<div class="d-block w-100 small text-muted mt-1">'
                    . htmlspecialchars(sprintf($more, count($mediaRecords), $mmTotal)) . '</div>';
            }
        } elseif (count($mediaRecords) >= self::PREVIEW_MAX_ITEMS_PER_ROW) {
            $cap = $lang->sL(self::LLL . 'preview.listview.row_items_capped') ?: 'Preview shows at most %d items per row';
            $html .= '<div class="d-block w-100 small text-muted mt-1">'
                . htmlspecialchars(sprintf($cap, self::PREVIEW_MAX_ITEMS_PER_ROW)) . '</div>';
        }

        return $html;
    }

    /**
     * Resolves the same media set as the frontend listview for one row, capped for backend preview.
     *
     * @return list<array<string, mixed>>
     */
    private function loadMediaForListviewRow(array $row, int $languageId): array
    {
        $rowUid = (int)($row['uid'] ?? 0);
        if ($rowUid <= 0) {
            return [];
        }
        $limitConfig = max(1, (int)($row['limit_items'] ?? 12));
        $limit = min(self::PREVIEW_MAX_ITEMS_PER_ROW, $limitConfig);
        $sortBy = (string)($row['sort_by'] ?? 'sorting');
        $selectionMode = (string)($row['selection_mode'] ?? 'manual');

        if ($selectionMode === 'category') {
            $categoryUids = $this->resolveCategoryUidsForRow($rowUid);

            return $this->mediaRepository->findByCategories($categoryUids, $languageId, $limit, $sortBy);
        }
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

        return array_slice($mediaRecords, 0, $limit);
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
        foreach ($rows as $refRow) {
            $fileRefUid = (int)($refRow['uid'] ?? 0);
            $mediaUid = (int)($refRow['uid_foreign'] ?? 0);
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

    /**
     * @param FileReference[] $fileReferences
     */
    private function resolveFirstPosterImageUrl(array $fileReferences): ?string
    {
        if ($fileReferences === []) {
            return null;
        }
        $fileReference = $fileReferences[0];
        try {
            $file = $fileReference->getOriginalFile();
            if ($file->exists() && $file->isImage()) {
                $publicUrl = $file->getPublicUrl();
                if ($publicUrl !== null && $publicUrl !== '') {
                    return (string)$publicUrl;
                }
                $processedFile = $file->process(
                    ProcessedFile::CONTEXT_IMAGEPREVIEW,
                    ['width' => 200, 'height' => 150]
                );
                if ($processedFile !== null) {
                    $url = $processedFile->getPublicUrl();
                    if ($url !== null && $url !== '') {
                        return (string)$url;
                    }
                }
            }
        } catch (ResourceDoesNotExistException) {
            return null;
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Normalize the `tx_mpcvidply_detail_page` field value which can arrive as:
     *  - int (legacy DB row array)
     *  - numeric string like "42" (old-style CSV / single group value)
     *  - `LazyRecordCollection` / iterable of Record objects (TYPO3 14 Record API)
     *  - Record / RecordInterface object (single relation)
     */
    private function resolveDetailPageUid(mixed $value): int
    {
        if ($value === null || $value === '' || $value === false) {
            return 0;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            $first = strtok($value, ',');
            return $first !== false ? (int)$first : 0;
        }
        if (is_object($value)) {
            if (method_exists($value, 'getUid')) {
                return (int)$value->getUid();
            }
            if ($value instanceof \Traversable || is_iterable($value)) {
                foreach ($value as $entry) {
                    if (is_object($entry) && method_exists($entry, 'getUid')) {
                        return (int)$entry->getUid();
                    }
                    if (is_array($entry)) {
                        return (int)($entry['uid'] ?? 0);
                    }
                    if (is_numeric($entry)) {
                        return (int)$entry;
                    }
                }
            }
        }
        if (is_array($value)) {
            $first = reset($value);
            if (is_object($first) && method_exists($first, 'getUid')) {
                return (int)$first->getUid();
            }
            if (is_array($first)) {
                return (int)($first['uid'] ?? 0);
            }
            if (is_numeric($first)) {
                return (int)$first;
            }
        }
        return 0;
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
