<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Backend\Preview;

use TYPO3\CMS\Backend\Preview\StandardContentPreviewRenderer;
use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridColumnItem;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

class VidPlyPreviewRenderer extends StandardContentPreviewRenderer
{
    private const LLL = 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:';

    /** @var array<int, string|null> */
    protected array $externalTypeLabelCache = [];

    private readonly ConnectionPool $connectionPool;
    private readonly FileRepository $fileRepository;

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?FileRepository $fileRepository = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->fileRepository = $fileRepository ?? GeneralUtility::makeInstance(FileRepository::class);
    }

    public function renderPageModulePreviewContent(GridColumnItem $item): string
    {
        $record = $item->getRecord();

        if (is_object($record) && method_exists($record, 'toArray')) {
            $record = $record->toArray();
        }

        $contentUid = (int)($record['uid'] ?? 0);
        $html = '';
        $lang = $this->getLanguageService();

        $mediaItems = $this->getMediaItems($contentUid);

        if ($mediaItems === []) {
            $noMedia = htmlspecialchars($lang->sL(self::LLL . 'preview.no_media') ?: 'No media items selected');
            $noMediaHint = htmlspecialchars($lang->sL(self::LLL . 'preview.no_media.hint') ?: 'Please add media items to this VidPly element');
            $html .= '<div class="callout callout-warning" style="margin-top: 10px;">';
            $html .= '<div class="callout-body">';
            $html .= '<strong>' . $noMedia . '</strong><br>';
            $html .= '<small>' . $noMediaHint . '</small>';
            $html .= '</div>';
            $html .= '</div>';
            return $html;
        }

        $untitledLabel = $lang->sL(self::LLL . 'preview.untitled') ?: 'Untitled';

        $html .= '<div class="mpc-vidply-preview-items" style="margin-top: 10px;">';

        if (count($mediaItems) > 1) {
            $playlistLabel = sprintf(
                $lang->sL(self::LLL . 'preview.playlist_count') ?: 'Playlist with %d items',
                count($mediaItems)
            );
            $html .= '<strong style="font-size: 14px; display: block; margin-bottom: 8px;">' . htmlspecialchars($playlistLabel) . '</strong>';
        }

        foreach ($mediaItems as $mediaItem) {
            $posterUrl = $this->getPosterImageUrl((int)$mediaItem['uid']);

            $html .= '<div class="callout callout-default" style="margin: 5px 0; padding: 8px; display: flex; align-items: center; gap: 10px;">';

            if ($posterUrl) {
                $html .= '<div style="flex-shrink: 0;">';
                $html .= '<img src="' . htmlspecialchars($posterUrl) . '" alt="" style="width: 60px; height: 60px; object-fit: cover; border-radius: var(--typo3-border-radius); border: 1px solid var(--typo3-border-color);" />';
                $html .= '</div>';
            } else {
                $mediaType = $mediaItem['media_type'] ?? 'video';
                $isAudio = in_array($mediaType, ['audio', 'soundcloud'], true);
                $fallbackImage = $isAudio ? 'audio.png' : 'video.png';
                $fallbackAbsPath = GeneralUtility::getFileAbsFileName('EXT:mpc_vidply/Resources/Public/Images/' . $fallbackImage);
                $fallbackUrl = $fallbackAbsPath ? PathUtility::getAbsoluteWebPath($fallbackAbsPath) : '';

                $html .= '<div style="flex-shrink: 0;">';
                if ($fallbackUrl !== '') {
                    $html .= '<img src="' . htmlspecialchars($fallbackUrl) . '" alt="" style="width: 60px; height: 60px; object-fit: contain; border-radius: var(--typo3-border-radius); border: 1px solid var(--typo3-border-color); background: var(--typo3-surface-base);" />';
                } else {
                    $html .= '<div style="width: 60px; height: 60px; background: var(--typo3-surface-base); border: 1px solid var(--typo3-border-color); border-radius: var(--typo3-border-radius);"></div>';
                }
                $html .= '</div>';
            }

            $html .= '<div style="flex: 1; min-width: 0;">';
            $html .= '<strong>' . htmlspecialchars($mediaItem['title'] ?? $untitledLabel) . '</strong>';

            if (($mediaItem['artist'] ?? '') !== '') {
                $html .= ' <span style="color: var(--typo3-text-color-secondary);">– ' . htmlspecialchars($mediaItem['artist']) . '</span>';
            }

            $html .= ' <span class="badge badge-info" style="margin-left: 5px;">';
            $badgeLabel = $this->getExternalTypeLabel((int)$mediaItem['uid'], $lang)
                ?? strtoupper((string)($mediaItem['media_type'] ?? 'video'));
            $html .= htmlspecialchars($badgeLabel);
            $html .= '</span>';
            $html .= '</div>';

            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    protected function getMediaItems(int $contentUid): array
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

    protected function getPosterImageUrl(int $mediaUid): ?string
    {
        if ($mediaUid === 0) {
            return null;
        }

        try {
            $fileReferences = $this->fileRepository->findByRelation('tx_mpcvidply_media', 'poster', $mediaUid);

            if ($fileReferences !== [] && isset($fileReferences[0])) {
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
        } catch (\Exception) {
        }

        return null;
    }

    protected function getExternalTypeLabel(int $mediaUid, LanguageService $lang): ?string
    {
        if ($mediaUid === 0) {
            return null;
        }
        if (array_key_exists($mediaUid, $this->externalTypeLabelCache)) {
            return $this->externalTypeLabelCache[$mediaUid];
        }

        try {
            $fileReferences = $this->fileRepository->findByRelation('tx_mpcvidply_media', 'media_file', $mediaUid);
            foreach ($fileReferences as $fileReference) {
                if (!$fileReference instanceof FileReference) {
                    continue;
                }
                $file = $fileReference->getOriginalFile();
                $ext = strtolower((string)$file->getExtension());
                if ($ext === 'externalaudio') {
                    return $this->externalTypeLabelCache[$mediaUid] = $lang->sL(self::LLL . 'preview.external_audio') ?: 'External Audio';
                }
                if ($ext === 'externalvideo') {
                    return $this->externalTypeLabelCache[$mediaUid] = $lang->sL(self::LLL . 'preview.external_video') ?: 'External Video';
                }
            }
        } catch (\Throwable) {
        }

        return $this->externalTypeLabelCache[$mediaUid] = null;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
