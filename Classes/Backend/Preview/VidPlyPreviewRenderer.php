<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Backend\Preview;

use TYPO3\CMS\Backend\Preview\StandardContentPreviewRenderer;
use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridColumnItem;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Custom preview renderer for VidPly content element
 */
class VidPlyPreviewRenderer extends StandardContentPreviewRenderer
{
    /**
     * @var array<int, string|null>
     */
    protected array $externalTypeLabelCache = [];

    public function renderPageModulePreviewContent(GridColumnItem $item): string
    {
        $record = $item->getRecord();
        
        // Convert Record object to array for TYPO3 14 compatibility
        if (is_object($record) && method_exists($record, 'toArray')) {
            $record = $record->toArray();
        }
        
        $contentUid = (int)($record['uid'] ?? 0);
        
        // Start with empty HTML (header is already shown by TYPO3)
        $html = '';
        
        // Get media items
        $mediaItems = $this->getMediaItems($contentUid);
        
        // Debug info - no media selected
        if (empty($mediaItems)) {
            $html .= '<div class="callout callout-warning" style="margin-top: 10px;">';
            $html .= '<div class="callout-body">';
            $html .= '<strong>No media items selected</strong><br>';
            $html .= '<small>Please add media items to this VidPlay element</small>';
            $html .= '</div>';
            $html .= '</div>';
            return $html;
        }
        
        if (!empty($mediaItems)) {
            $html .= '<div class="mpc-vidply-preview-items" style="margin-top: 10px;">';
            
            // Show playlist indicator for multiple items
            if (count($mediaItems) > 1) {
                $html .= '<strong style="font-size: 14px; display: block; margin-bottom: 8px;">Playlist with ' . count($mediaItems) . ' items</strong>';
            }
            
            // List all media items (no large image at top)
            foreach ($mediaItems as $index => $mediaItem) {
                $posterUrl = $this->getPosterImageUrl((int)$mediaItem['uid']);
                
                $html .= '<div class="callout callout-default" style="margin: 5px 0; padding: 8px; display: flex; align-items: center; gap: 10px;">';
                
                // Always show either poster thumbnail or icon
                if ($posterUrl) {
                    // Show poster thumbnail
                    $html .= '<div style="flex-shrink: 0;">';
                    $html .= '<img src="' . htmlspecialchars($posterUrl) . '" alt="Poster" style="width: 60px; height: 60px; object-fit: cover; border-radius: var(--typo3-border-radius); border: 1px solid var(--typo3-border-color);" />';
                    $html .= '</div>';
                } else {
                    // Show fallback image (audio/video) when no poster is set
                    $mediaType = $mediaItem['media_type'] ?? 'video';
                    $isAudio = in_array($mediaType, ['audio', 'soundcloud'], true);
                    $fallbackImage = $isAudio ? 'audio.png' : 'video.png';
                    $fallbackAbsPath = GeneralUtility::getFileAbsFileName('EXT:mpc_vidply/Resources/Public/Images/' . $fallbackImage);
                    $fallbackUrl = $fallbackAbsPath ? PathUtility::getAbsoluteWebPath($fallbackAbsPath) : '';

                    $html .= '<div style="flex-shrink: 0;">';
                    if ($fallbackUrl !== '') {
                        $html .= '<img src="' . htmlspecialchars($fallbackUrl) . '" alt="" style="width: 60px; height: 60px; object-fit: contain; border-radius: var(--typo3-border-radius); border: 1px solid var(--typo3-border-color); background: var(--typo3-surface-base);" />';
                    } else {
                        // If for some reason the fallback image is missing, show an empty box
                        $html .= '<div style="width: 60px; height: 60px; background: var(--typo3-surface-base); border: 1px solid var(--typo3-border-color); border-radius: var(--typo3-border-radius);"></div>';
                    }
                    $html .= '</div>';
                }
                
                $html .= '<div style="flex: 1; min-width: 0;">';
                $html .= '<strong>' . htmlspecialchars($mediaItem['title'] ?? 'Untitled') . '</strong>';
                
                if (!empty($mediaItem['artist'])) {
                    $html .= ' <span style="color: var(--typo3-text-color-secondary);">– ' . htmlspecialchars($mediaItem['artist']) . '</span>';
                }
                
                $html .= ' <span class="badge badge-info" style="margin-left: 5px;">';
                $badgeLabel = $this->getExternalTypeLabel((int)$mediaItem['uid'])
                    ?? strtoupper((string)($mediaItem['media_type'] ?? 'video'));
                $html .= htmlspecialchars($badgeLabel);
                $html .= '</span>';
                $html .= '</div>';
                
                $html .= '</div>';
            }
            
            $html .= '</div>';
        }
        
        // Add dimensions if set
        if (!empty($record['tx_mpcvidply_width']) || !empty($record['tx_mpcvidply_height'])) {
            $html .= '<div style="margin-top: 8px; font-size: 12px; color: var(--typo3-text-color-secondary);">';
            $html .= '<strong>Dimensions:</strong> ';
            if (!empty($record['tx_mpcvidply_width'])) {
                $html .= htmlspecialchars((string)$record['tx_mpcvidply_width']) . 'px';
            }
            if (!empty($record['tx_mpcvidply_width']) && !empty($record['tx_mpcvidply_height'])) {
                $html .= ' × ';
            }
            if (!empty($record['tx_mpcvidply_height'])) {
                $html .= htmlspecialchars((string)$record['tx_mpcvidply_height']) . 'px';
            }
            $html .= '</div>';
        }
        
        return $html;
    }
    
    protected function getMediaItems(int $contentUid): array
    {
        if ($contentUid === 0) {
            return [];
        }
        
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable('tx_mpcvidply_media');
        
        $records = $queryBuilder
            ->select('media.*')
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
            $fileRepository = GeneralUtility::makeInstance(FileRepository::class);
            $fileReferences = $fileRepository->findByRelation('tx_mpcvidply_media', 'poster', $mediaUid);
            
            if (!empty($fileReferences) && isset($fileReferences[0])) {
                /** @var \TYPO3\CMS\Core\Resource\FileReference $fileReference */
                $fileReference = $fileReferences[0];
                $file = $fileReference->getOriginalFile();
                
                if ($file && $file->exists() && $file->isImage()) {
                    // Try to get public URL directly first (for simple cases)
                    $publicUrl = $file->getPublicUrl();
                    
                    if ($publicUrl) {
                        return $publicUrl;
                    }
                    
                    // Fall back to processed file
                    $processingConfiguration = [
                        'width' => 200,
                        'height' => 150,
                    ];
                    
                    $processedFile = $file->process(
                        \TYPO3\CMS\Core\Resource\ProcessedFile::CONTEXT_IMAGEPREVIEW,
                        $processingConfiguration
                    );
                    
                    if ($processedFile) {
                        return $processedFile->getPublicUrl();
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently fail - no image available
        }
        
        return null;
    }

    /**
     * If the attached media file is an online-media container (externalaudio/externalvideo),
     * return a nicer label for the page-module preview.
     */
    protected function getExternalTypeLabel(int $mediaUid): ?string
    {
        if ($mediaUid === 0) {
            return null;
        }
        if (array_key_exists($mediaUid, $this->externalTypeLabelCache)) {
            return $this->externalTypeLabelCache[$mediaUid];
        }

        try {
            $fileRepository = GeneralUtility::makeInstance(FileRepository::class);
            $fileReferences = $fileRepository->findByRelation('tx_mpcvidply_media', 'media_file', $mediaUid);
            foreach ($fileReferences as $fileReference) {
                if (!$fileReference instanceof FileReference) {
                    continue;
                }
                $file = $fileReference->getOriginalFile();
                if ($file === null) {
                    continue;
                }
                $ext = strtolower((string)$file->getExtension());
                if ($ext === 'externalaudio') {
                    return $this->externalTypeLabelCache[$mediaUid] = 'External Audio';
                }
                if ($ext === 'externalvideo') {
                    return $this->externalTypeLabelCache[$mediaUid] = 'External Video';
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        return $this->externalTypeLabelCache[$mediaUid] = null;
    }
}

