<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Backend\Preview;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Preview\StandardContentPreviewRenderer;
use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridColumnItem;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

final class VidPlyPreviewRenderer extends StandardContentPreviewRenderer implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const LLL = 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:';

    private readonly ConnectionPool $connectionPool;
    private readonly ResourceFactory $resourceFactory;

    /** @var array<int, FileReference[]> */
    private array $posterRefsByMediaUid = [];

    /** @var array<int, FileReference[]> */
    private array $mediaFileRefsByMediaUid = [];

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?ResourceFactory $resourceFactory = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->resourceFactory = $resourceFactory ?? GeneralUtility::makeInstance(ResourceFactory::class);
    }

    public function renderPageModulePreviewContent(GridColumnItem $item): string
    {
        $record = $item->getRecord();

        if (is_object($record) && method_exists($record, 'toArray')) {
            $record = $record->toArray();
        }

        $contentUid = (int)($record['uid'] ?? 0);
        $l10nSource = (int)($record['l18n_parent'] ?? $record['l10n_parent'] ?? 0);
        $html = '';
        $lang = $this->getLanguageService();

        $mediaItems = $this->getMediaItems($contentUid, $l10nSource);

        if ($mediaItems === []) {
            $noMedia = htmlspecialchars($lang->sL(self::LLL . 'preview.no_media') ?: 'No media items selected');
            $noMediaHint = htmlspecialchars($lang->sL(self::LLL . 'preview.no_media.hint') ?: 'Please add media items to this VidPly element');
            $html .= '<div class="callout callout-warning w-100 mt-2"><div class="callout-body">';
            $html .= '<strong>' . $noMedia . '</strong><br>';
            $html .= '<small class="text-muted">' . $noMediaHint . '</small>';
            $html .= '</div></div>';
            return $html;
        }

        $mediaUids = array_values(array_filter(
            array_map(static fn(array $row): int => (int)($row['uid'] ?? 0), $mediaItems),
            static fn(int $uid): bool => $uid > 0
        ));
        $this->prefetchFileReferences($mediaUids);

        $untitledLabel = $lang->sL(self::LLL . 'preview.untitled') ?: 'Untitled';

        $html .= '<div class="mpc-vidply-preview-items w-100 mt-2">';

        if (count($mediaItems) > 1) {
            $playlistLabel = sprintf(
                $lang->sL(self::LLL . 'preview.playlist_count') ?: 'Playlist with %d items',
                count($mediaItems)
            );
            $html .= '<div class="d-block w-100 small fw-bold mb-2">' . htmlspecialchars($playlistLabel) . '</div>';
        }

        $html .= '<div class="d-flex flex-wrap gap-2 align-items-stretch w-100">';

        $posterAltFormat = $lang->sL(self::LLL . 'preview.poster_alt') ?: 'Poster for "%s"';
        $iconAltLabel = $lang->sL(self::LLL . 'preview.icon_alt') ?: 'VidPly item';

        foreach ($mediaItems as $mediaItem) {
            $mediaUid = (int)$mediaItem['uid'];
            $posterUrl = $this->getPosterImageUrl($mediaUid);
            $itemTitle = (string)($mediaItem['title'] ?? '');
            if ($itemTitle === '') {
                $itemTitle = $untitledLabel;
            }

            $html .= '<div class="callout callout-default d-flex align-items-center gap-2 p-2 min-w-0" '
                . 'style="flex:1 1 13.75rem;max-width:100%;">';

            if ($posterUrl) {
                $posterAlt = sprintf($posterAltFormat, $itemTitle);
                $html .= '<div class="flex-shrink-0">';
                $html .= '<img class="rounded object-fit-cover border" src="' . htmlspecialchars($posterUrl) . '" alt="'
                    . htmlspecialchars($posterAlt) . '" width="60" height="60" style="width:3.75rem;height:3.75rem;" />';
                $html .= '</div>';
            } else {
                $fallbackAbsPath = GeneralUtility::getFileAbsFileName('EXT:mpc_vidply/Resources/Public/Icons/Extension.svg');
                $fallbackUrl = $fallbackAbsPath ? PathUtility::getAbsoluteWebPath($fallbackAbsPath) : '';

                $html .= '<div class="flex-shrink-0">';
                if ($fallbackUrl !== '') {
                    $html .= '<img class="rounded border bg-light object-fit-contain" src="'
                        . htmlspecialchars($fallbackUrl) . '" alt="" role="presentation" width="60" height="60" '
                        . 'style="width:3.75rem;height:3.75rem;" />';
                } else {
                    $html .= '<div class="rounded border bg-light" role="img" aria-label="'
                        . htmlspecialchars($iconAltLabel) . '" style="width:3.75rem;height:3.75rem;"></div>';
                }
                $html .= '</div>';
            }

            $html .= '<div class="flex-grow-1 min-w-0">';
            $html .= '<strong class="d-inline">' . htmlspecialchars($mediaItem['title'] ?? $untitledLabel) . '</strong>';

            if (($mediaItem['artist'] ?? '') !== '') {
                $html .= ' <span class="text-body-secondary">– ' . htmlspecialchars($mediaItem['artist']) . '</span>';
            }

            $html .= ' <span class="badge text-bg-info align-middle ms-1">';
            $badgeLabel = $this->getExternalTypeLabel($mediaUid, $lang)
                ?? strtoupper((string)($mediaItem['media_type'] ?? 'video'));
            $html .= htmlspecialchars($badgeLabel);
            $html .= '</span>';
            $html .= '</div>';

            $html .= '</div>';
        }

        $html .= '</div></div>';

        return $html;
    }

    /**
     * Batch-fetch poster and media_file references for all media UIDs in a single query
     * to avoid N+1 queries when rendering playlist previews.
     *
     * @param int[] $mediaUids
     */
    private function prefetchFileReferences(array $mediaUids): void
    {
        $this->posterRefsByMediaUid = [];
        $this->mediaFileRefsByMediaUid = [];

        if ($mediaUids === []) {
            return;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $rows = $qb
            ->select('uid', 'uid_foreign', 'fieldname')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tx_mpcvidply_media')),
                $qb->expr()->in('fieldname', $qb->createNamedParameter(['poster', 'media_file'], Connection::PARAM_STR_ARRAY)),
                $qb->expr()->in('uid_foreign', $qb->createNamedParameter($mediaUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('uid_foreign', 'ASC')
            ->addOrderBy('sorting_foreign', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $uid = (int)($row['uid'] ?? 0);
            $uidForeign = (int)($row['uid_foreign'] ?? 0);
            $fieldName = (string)($row['fieldname'] ?? '');
            if ($uid <= 0 || $uidForeign <= 0 || $fieldName === '') {
                continue;
            }

            try {
                $fileReference = $this->resourceFactory->getFileReferenceObject($uid);
            } catch (ResourceDoesNotExistException) {
                continue;
            }

            if ($fieldName === 'poster') {
                $this->posterRefsByMediaUid[$uidForeign] ??= [];
                $this->posterRefsByMediaUid[$uidForeign][] = $fileReference;
            } else {
                $this->mediaFileRefsByMediaUid[$uidForeign] ??= [];
                $this->mediaFileRefsByMediaUid[$uidForeign][] = $fileReference;
            }
        }
    }

    /**
     * @param int $fallbackContentUid `tt_content.l18n_parent` when $contentUid is a localized CE (same as FE MM lookup)
     * @return list<array<string, mixed>>
     */
    private function getMediaItems(int $contentUid, int $fallbackContentUid = 0): array
    {
        if ($fallbackContentUid > 0 && $fallbackContentUid !== $contentUid) {
            $rows = $this->fetchMediaRowsForContentUid($fallbackContentUid);
            if ($rows === []) {
                $rows = $this->fetchMediaRowsForContentUid($contentUid);
            }
        } else {
            $rows = $this->fetchMediaRowsForContentUid($contentUid);
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchMediaRowsForContentUid(int $contentUid): array
    {
        if ($contentUid === 0) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_media');

        $records = $queryBuilder
            ->select('media.uid', 'media.title', 'media.artist', 'media.media_type')
            ->from('tx_mpcvidply_media', 'media')
            ->join(
                'media',
                'tx_mpcvidply_content_media_mm',
                'mm',
                $queryBuilder->expr()->eq('media.uid', $queryBuilder->quoteIdentifier('mm.uid_foreign'))
            )
            ->where(
                $queryBuilder->expr()->eq('mm.uid_local', $queryBuilder->createNamedParameter($contentUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('media.deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('media.hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('mm.sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return $records ?: [];
    }

    private function getPosterImageUrl(int $mediaUid): ?string
    {
        if ($mediaUid === 0) {
            return null;
        }

        try {
            $fileReferences = $this->posterRefsByMediaUid[$mediaUid] ?? [];

            if ($fileReferences !== []) {
                $fileReference = $fileReferences[0];
                $file = $fileReference->getOriginalFile();

                if ($file->exists() && $file->isImage()) {
                    $publicUrl = $file->getPublicUrl();
                    if ($publicUrl) {
                        return $publicUrl;
                    }

                    $processedFile = $file->process(
                        \TYPO3\CMS\Core\Resource\ProcessedFile::CONTEXT_IMAGEPREVIEW,
                        ['width' => 200, 'height' => 150]
                    );

                    if ($processedFile) {
                        return $processedFile->getPublicUrl();
                    }
                }
            }
        } catch (ResourceDoesNotExistException $e) {
            $this->logger?->debug('VidPly preview: poster file reference missing', [
                'mediaUid' => $mediaUid,
                'exception' => $e,
            ]);
        } catch (\Throwable $e) {
            $this->logger?->warning('VidPly preview: failed to resolve poster URL', [
                'mediaUid' => $mediaUid,
                'exception' => $e,
            ]);
        }

        return null;
    }

    private function getExternalTypeLabel(int $mediaUid, LanguageService $lang): ?string
    {
        if ($mediaUid === 0) {
            return null;
        }

        $fileReferences = $this->mediaFileRefsByMediaUid[$mediaUid] ?? [];
        foreach ($fileReferences as $fileReference) {
            if (!$fileReference instanceof FileReference) {
                continue;
            }
            try {
                $ext = strtolower((string)$fileReference->getOriginalFile()->getExtension());
            } catch (\Throwable) {
                continue;
            }
            if ($ext === 'externalaudio') {
                return $lang->sL(self::LLL . 'preview.external_audio') ?: 'External Audio';
            }
            if ($ext === 'externalvideo') {
                return $lang->sL(self::LLL . 'preview.external_video') ?: 'External Video';
            }
        }

        return null;
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
