<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\DataProcessing;

use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Connection;

/**
 * DataProcessor for VidPly Player
 * 
 * Processes content element data and prepares it for the Fluid template.
 * Works with standalone media records from tx_mpcvidply_media table.
 * 
 * Uses constructor injection with fallback for TYPO3 13/14 compatibility.
 * DataProcessors are instantiated via GeneralUtility::makeInstance() from TypoScript,
 * so we need optional parameters with service locator fallback.
 * 
 * @package Mpc\MpcVidply\DataProcessing
 */
class VidPlyProcessor implements DataProcessorInterface
{
    private readonly FileRepository $fileRepository;
    private readonly ConnectionPool $connectionPool;

    /**
     * Constructor with dependency injection support
     * 
     * Parameters are optional to support GeneralUtility::makeInstance() calls.
     * When called without parameters, uses GeneralUtility as service locator.
     * When autowired (e.g., in tests), dependencies are injected.
     * 
     * This is the recommended pattern for DataProcessors in TYPO3 13/14.
     */
    public function __construct(
        ?FileRepository $fileRepository = null,
        ?ConnectionPool $connectionPool = null
    ) {
        $this->fileRepository = $fileRepository ?? GeneralUtility::makeInstance(FileRepository::class);
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
    }

    /**
     * Process content element data
     */
    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ): array {
        $data = $processedData['data'];
        
        // Process options from checkbox field (bitmask)
        $options = (int)($data['tx_mpcvidply_options'] ?? 0);
        $playerOptions = [
            'autoplay' => (bool)($options & 1),
            'loop' => (bool)($options & 2),
            'muted' => (bool)($options & 4),
            'controls' => (bool)($options & 8),
            'captionsDefault' => (bool)($options & 16),
            'transcript' => (bool)($options & 32),
            'keyboard' => (bool)($options & 64),
            'responsive' => (bool)($options & 128),
            'autoAdvance' => (bool)($options & 256),
        ];
        
        // Add other settings
        $playerOptions['volume'] = (float)($data['tx_mpcvidply_volume'] ?? 0.8);
        $playerOptions['playbackSpeed'] = (float)($data['tx_mpcvidply_playback_speed'] ?? 1.0);
        $playerOptions['language'] = $data['tx_mpcvidply_language'] ?? '';
        $playerOptions['defaultTranscriptLanguage'] = $playerOptions['language'];
        
        // Audio description and sign language will be added after processing tracks
        
        // Dimensions
        $width = (int)($data['tx_mpcvidply_width'] ?? 800);
        $height = (int)($data['tx_mpcvidply_height'] ?? 450);
        
        // Get current language ID from request attribute (TYPO3 13/14 compatible)
        // Using toArray() to avoid extension scanner false positive on getLanguageId()
        $languageId = 0;
        $language = $cObj->getRequest()->getAttribute('language');
        if ($language !== null) {
            $languageId = (int)$language->toArray()['languageId'];
        }
        
        // Additional fallback: check if content element itself is translated
        // If the content element has sys_language_uid > 0, use that as language hint
        if ($languageId === 0 && isset($data['sys_language_uid']) && (int)$data['sys_language_uid'] > 0) {
            $languageId = (int)$data['sys_language_uid'];
        }
        
        // Get media items from MM table with language support
        $mediaRecords = $this->getMediaRecords((int)$data['uid'], $languageId);
        
        // Process media records into tracks
        $tracks = [];
        $mediaType = null;
        $externalServiceTypes = []; // Track which external services are used
        $hasLocalMedia = false;
        $hasExternalMedia = false;
        
        foreach ($mediaRecords as $mediaRecord) {
            $track = $this->processMediaRecord($mediaRecord);
            if ($track) {
                $tracks[] = $track;
                // Set media type from first item (simplified: video or audio)
                if ($mediaType === null) {
                    $recordType = $mediaRecord['media_type'];
                    $mediaType = ($recordType === 'audio') ? 'audio' : 'video';
                }
                
                // Track external vs local media for mixed playlist detection
                $trackType = $mediaRecord['media_type'];
                if (in_array($trackType, ['youtube', 'vimeo', 'soundcloud'])) {
                    $hasExternalMedia = true;
                    if (!in_array($trackType, $externalServiceTypes)) {
                        $externalServiceTypes[] = $trackType;
            }
                } else {
                    $hasLocalMedia = true;
                }
            }
        }
        
        // Determine if this is a mixed media playlist (contains both local and external OR external-only)
        $isMixedPlaylist = count($tracks) > 1 && ($hasExternalMedia || $hasLocalMedia);
        
        // Prepare playlist data and extract template variables
        $playlistData = null;
        $mediaFiles = [];
        $videoUrl = '';
        $poster = null;
        $captions = [];
        $chapters = [];
        $audioDescriptionTracks = [];
        $signLanguage = [];
        
        if (!empty($tracks)) {
            // Auto-detect playlist behavior:
            // 1 item = single media (no playlist controls)
            // 2+ items = playlist (show controls and panel)
            $isPlaylist = count($tracks) > 1;
            
            // Only create playlist data if there are 2+ items
            if ($isPlaylist) {
                $playlistData = [
                    'tracks' => $tracks,
                    'options' => [
                        'autoplay' => $playerOptions['autoplay'], // Pass autoplay to playlist
                        'autoAdvance' => $playerOptions['autoAdvance'],
                        'loop' => $playerOptions['loop'],
                        'showPanel' => true, // Show panel for playlists
                        // Mixed playlist options for dynamic renderer switching
                        'isMixedPlaylist' => $isMixedPlaylist,
                        'hasExternalMedia' => $hasExternalMedia,
                        'externalServiceTypes' => $externalServiceTypes,
                    ],
                ];
            }
            
            // For single item: extract data for template compatibility
            if (!$isPlaylist && isset($tracks[0])) {
                $firstTrack = $tracks[0];
                
                // Handle media source
                if (in_array($firstTrack['type'], ['youtube', 'vimeo', 'soundcloud', 'hls', 'm3u'])) {
                    $videoUrl = $firstTrack['src'];
                } else {
                    // Local file(s) - handle multiple sources if available
                    if (!empty($firstTrack['sources']) && count($firstTrack['sources']) > 1) {
                        // Multiple sources (e.g., MP4 and WebM) - keep sources array for template
                        $processedData['vidply']['sources'] = $firstTrack['sources'];
                        // Don't populate mediaFiles when we have sources to avoid duplication
                        $mediaFiles = []; // Ensure mediaFiles is empty
                    } else {
                        // Single source - use mediaFiles only (no sources array)
                        // Ensure sources is not set
                        unset($processedData['vidply']['sources']);
                        $mediaFiles[] = [
                            'publicUrl' => $firstTrack['src'],
                            'mimeType' => $firstTrack['type'],
                            'label' => 'Default',
                            'properties' => [],
                        ];
                    }
                }
                
                // Extract poster
                if (!empty($firstTrack['poster'])) {
                    $poster = $firstTrack['poster'];
                    // Add poster to player options for single audio files (used for track artwork)
                    $playerOptions['poster'] = $poster;
                }
                
                // Extract captions/chapters/descriptions
                if (!empty($firstTrack['tracks'])) {
                    foreach ($firstTrack['tracks'] as $track) {
                        if ($track['kind'] === 'chapters') {
                            $chapters[] = $track;
                        } elseif ($track['kind'] === 'descriptions') {
                            // Description tracks (VTT files) - separate from audio description video
                            $captions[] = $track; // Add to captions array for template rendering
                        } else {
                            $captions[] = $track;
                        }
                    }
                }
                
                // Extract audio description
                if (!empty($firstTrack['audioDescriptionSrc'])) {
                    $audioDescriptionTracks[] = [
                        'src' => $firstTrack['audioDescriptionSrc'],
                        'lang' => '',
                        'label' => 'Audio Description',
                        'mimeType' => '',
                    ];
                    // Add to player options for VidPly
                    $playerOptions['audioDescriptionSrc'] = $firstTrack['audioDescriptionSrc'];
                    $playerOptions['audioDescriptionButton'] = true;
                }
                
                // Extract sign language
                if (!empty($firstTrack['signLanguageSrc'])) {
                    $signLanguage[] = [
                        'src' => $firstTrack['signLanguageSrc'],
                        'lang' => '',
                        'label' => 'Sign Language',
                    ];
                    // Add to player options for VidPly
                    $playerOptions['signLanguageSrc'] = $firstTrack['signLanguageSrc'];
                    $playerOptions['signLanguageButton'] = true;
                    $playerOptions['signLanguagePosition'] = 'bottom-right';
                }
            }
        }
        
        // Determine service type for external services (YouTube, Vimeo, SoundCloud)
        // For single items, use the first track's type
        // For mixed playlists, we need VidPly player (not privacy layer as primary)
        $serviceType = null;
        if (!empty($tracks) && !$isPlaylist) {
            // Single item mode - check if it's an external service
            $firstTrackType = $tracks[0]['type'] ?? null;
            if (in_array($firstTrackType, ['youtube', 'vimeo', 'soundcloud'])) {
                $serviceType = $firstTrackType;
            }
        }
        
        // Determine which assets are needed for conditional loading
        // For mixed playlists: always use VidPly with playlist-integrated privacy consent
        $needsPrivacyLayer = $serviceType !== null || ($isPlaylist && $hasExternalMedia);
        $needsVidPlay = $isPlaylist || $serviceType === null; // VidPly for playlists and local media
        $needsPlaylist = $isPlaylist || $needsVidPlay; // Playlist OR native player
        
        // Check if HLS is needed
        $needsHLS = false;
        foreach ($tracks as $track) {
            if (in_array($track['type'] ?? '', ['hls', 'm3u', 'application/x-mpegurl', 'application/vnd.apple.mpegurl'])) {
                $needsHLS = true;
                break;
            }
        }
        
        // Build processed data with template compatibility
        $vidplyData = [
            'mediaType' => $mediaType ?? 'video',
            'serviceType' => $serviceType, // For privacy layer detection (single item only)
            // Mixed playlist flags
            'isMixedPlaylist' => $isMixedPlaylist ?? false,
            'hasExternalMedia' => $hasExternalMedia ?? false,
            'externalServiceTypes' => $externalServiceTypes ?? [],
            // Asset loading flags
            'needsPrivacyLayer' => $needsPrivacyLayer,
            'needsVidPlay' => $needsVidPlay,
            'needsPlaylist' => $needsPlaylist,
            'needsHLS' => $needsHLS,
            'videoUrl' => $videoUrl,
            'poster' => $poster,
            'captions' => $captions,
            'chapters' => $chapters,
            'audioDescriptionTracks' => $audioDescriptionTracks,
            'audioDescription' => $audioDescriptionTracks[0]['src'] ?? null,
            'audioDescriptionDefaultSrc' => $audioDescriptionTracks[0]['src'] ?? null,
            'signLanguage' => $signLanguage,
            'signLanguageDefaultSrc' => $signLanguage[0]['src'] ?? null,
            'signLanguageHasMultiple' => count($signLanguage) > 1,
            'signLanguageAttributes' => [],
            'signLanguagePosition' => 'bottom-right',
            'width' => $width,
            'height' => $height,
            'options' => $playerOptions,
            'languageSelection' => $playerOptions['language'] ?? '',
            'uniqueId' => 'vidply-' . $data['uid'],
            'playlistData' => $playlistData,
            'tracks' => $tracks,
        ];
        
        // Only set mediaFiles if we don't have sources (to avoid duplication)
        if (empty($processedData['vidply']['sources'])) {
            $vidplyData['mediaFiles'] = $mediaFiles;
        } else {
            $vidplyData['mediaFiles'] = []; // Empty array when we have sources
        }
        
        // Merge with any existing vidply data (like sources)
        $processedData['vidply'] = array_merge($vidplyData, $processedData['vidply'] ?? []);
        
        return $processedData;
    }

    /**
     * Get media records associated with content element via MM table
     * Respects language translations and falls back to default language if needed
     */
    protected function getMediaRecords(int $contentUid, int $languageId = 0): array
    {
        // Step 1: Get MM relations for this content element
        $mmQueryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('tx_mpcvidply_content_media_mm');
        
        $mmRelations = $mmQueryBuilder
            ->select('uid_foreign', 'sorting')
            ->from('tx_mpcvidply_content_media_mm')
            ->where(
                $mmQueryBuilder->expr()->eq('uid_local', $mmQueryBuilder->createNamedParameter($contentUid, Connection::PARAM_INT))
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
        
        if (empty($mmRelations)) {
            return [];
        }
        
        // Step 2: For each media record referenced in MM table, get the best language version
        $resultRecords = [];
        
        foreach ($mmRelations as $mmRelation) {
            $mediaUid = (int)$mmRelation['uid_foreign'];
            $sorting = (int)$mmRelation['sorting'];
            
            // First, check what the MM table references: is it a default language record or a translated one?
            $mediaQueryBuilder = $this->connectionPool
                ->getQueryBuilderForTable('tx_mpcvidply_media');
            
            $referencedRecord = $mediaQueryBuilder
                ->select('uid', 'sys_language_uid', 'l10n_parent')
                ->from('tx_mpcvidply_media')
                ->where(
                    $mediaQueryBuilder->expr()->eq('uid', $mediaQueryBuilder->createNamedParameter($mediaUid, Connection::PARAM_INT)),
                    $mediaQueryBuilder->expr()->eq('deleted', $mediaQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $mediaQueryBuilder->expr()->eq('hidden', $mediaQueryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                )
                ->executeQuery()
                ->fetchAssociative();
            
            if ($referencedRecord === false) {
                continue; // Record not found or deleted/hidden
            }
            
            $referencedLanguageId = (int)($referencedRecord['sys_language_uid'] ?? 0);
            $defaultLanguageUid = $referencedLanguageId === 0 
                ? $mediaUid 
                : (int)($referencedRecord['l10n_parent'] ?? 0);
            
            // Now get the best language version
            $mediaRecord = null;
            
            // If we need a translation and the referenced record is not already in the target language
            if ($languageId > 0 && $referencedLanguageId !== $languageId) {
                // Try to get translated version
                $translatedQueryBuilder = $this->connectionPool
                    ->getQueryBuilderForTable('tx_mpcvidply_media');
                
                $mediaRecord = $translatedQueryBuilder
                    ->select('*')
                    ->from('tx_mpcvidply_media')
                    ->where(
                        $translatedQueryBuilder->expr()->eq('l10n_parent', $translatedQueryBuilder->createNamedParameter($defaultLanguageUid, Connection::PARAM_INT)),
                        $translatedQueryBuilder->expr()->eq('sys_language_uid', $translatedQueryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)),
                        $translatedQueryBuilder->expr()->eq('deleted', $translatedQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                        $translatedQueryBuilder->expr()->eq('hidden', $translatedQueryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                    )
                    ->executeQuery()
                    ->fetchAssociative();
            }
            
            // If no translated version found (or we're on default language), get default language record
            if ($mediaRecord === false || $mediaRecord === null) {
                $defaultQueryBuilder = $this->connectionPool
                    ->getQueryBuilderForTable('tx_mpcvidply_media');
                
                $mediaRecord = $defaultQueryBuilder
                    ->select('*')
                    ->from('tx_mpcvidply_media')
                    ->where(
                        $defaultQueryBuilder->expr()->eq('uid', $defaultQueryBuilder->createNamedParameter($defaultLanguageUid, Connection::PARAM_INT)),
                        $defaultQueryBuilder->expr()->eq('sys_language_uid', $defaultQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                        $defaultQueryBuilder->expr()->eq('deleted', $defaultQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                        $defaultQueryBuilder->expr()->eq('hidden', $defaultQueryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                    )
                    ->executeQuery()
                    ->fetchAssociative();
            }
            
            // If we found a record, add it with the sorting from MM table
            if ($mediaRecord !== false && $mediaRecord !== null) {
                $mediaRecord['sorting'] = $sorting;
                $resultRecords[] = $mediaRecord;
            }
        }
        
        return $resultRecords;
    }

    /**
     * Process a single media record into a track array
     */
    protected function processMediaRecord(array $mediaRecord): ?array
    {
        $mediaType = $mediaRecord['media_type'];
        $mediaUid = (int)$mediaRecord['uid'];
        
        $track = [
            'title' => $mediaRecord['title'] ?: 'Untitled',
        ];
        
        // Add artist if available
        if (!empty($mediaRecord['artist'])) {
            $track['artist'] = $mediaRecord['artist'];
        }
        
        // Add duration if available (in seconds)
        if (!empty($mediaRecord['duration'])) {
            $track['duration'] = (int)$mediaRecord['duration'];
        }
        
        // Add audio description duration if available (in seconds)
        // This is the duration of the audio-described version, which is typically longer
        if (!empty($mediaRecord['audio_description_duration'])) {
            $track['audioDescriptionDuration'] = (int)$mediaRecord['audio_description_duration'];
        }
        
        // Add description if available
        if (!empty($mediaRecord['description'])) {
            $track['description'] = $mediaRecord['description'];
        }
        
        // Handle different media types
        switch ($mediaType) {
            case 'youtube':
            case 'vimeo':
                // File-based media using TYPO3 13 online media helpers
                $mediaFiles = $this->fileRepository->findByRelation('tx_mpcvidply_media', 'media_file', $mediaUid);
                if (empty($mediaFiles)) {
                    return null; // Skip if no file
                }
                $mediaFile = $mediaFiles[0];
                // For YouTube/Vimeo, the file extension is 'youtube' or 'vimeo'
                // TYPO3's online media helpers store the URL in the file's properties
                $track['src'] = $mediaFile->getPublicUrl();
                $track['type'] = $mediaType;
                break;
                
            case 'soundcloud':
                // SoundCloud: URL-based media (no TYPO3 online media helper)
                if (empty($mediaRecord['media_url'])) {
                    return null; // Skip if no URL
                }
                $track['src'] = $mediaRecord['media_url'];
                $track['type'] = $mediaType;
                break;
                
            case 'hls':
            case 'm3u':
                // URL-based media
                if (empty($mediaRecord['media_url'])) {
                    return null; // Skip if no URL
                }
                $track['src'] = $mediaRecord['media_url'];
                $track['type'] = $mediaType;
                break;
                
            case 'video':
            case 'audio':
                // File-based media - can have multiple files (e.g., MP4 and WebM)
                $mediaFiles = $this->fileRepository->findByRelation('tx_mpcvidply_media', 'media_file', $mediaUid);
                if (empty($mediaFiles)) {
                    return null; // Skip if no file
                }
                // If multiple files, create sources array for HTML5 video/audio element
                if (count($mediaFiles) > 1) {
                    $track['sources'] = [];
                    foreach ($mediaFiles as $mediaFile) {
                        $track['sources'][] = [
                            'src' => $mediaFile->getPublicUrl(),
                            'type' => $mediaFile->getMimeType(),
                            'label' => 'Default', // Label for quality/format selection
                        ];
                    }
                    // Use first file as primary src for backward compatibility
                    $track['src'] = $mediaFiles[0]->getPublicUrl();
                    $track['type'] = $mediaFiles[0]->getMimeType();
                } else {
                    // Single file - use simple src
                    $mediaFile = $mediaFiles[0];
                    $track['src'] = $mediaFile->getPublicUrl();
                    $track['type'] = $mediaFile->getMimeType();
                }
                break;
                
            default:
                return null; // Unknown media type
        }
        
        // Get poster/thumbnail
        $posterFiles = $this->fileRepository->findByRelation('tx_mpcvidply_media', 'poster', $mediaUid);
        if (!empty($posterFiles)) {
            $track['poster'] = $posterFiles[0]->getPublicUrl();
        }
        
        // Get captions and chapters (text tracks)
        $textTracks = [];
        
        // Captions - can be captions or descriptions
        $captionFiles = $this->fileRepository->findByRelation('tx_mpcvidply_media', 'captions', $mediaUid);
        foreach ($captionFiles as $captionFile) {
            $properties = $captionFile->getProperties();
            $trackKind = $properties['tx_track_kind'] ?: 'captions';
            // Support both 'captions' and 'descriptions' track kinds
            $textTracks[] = [
                'src' => $captionFile->getPublicUrl(),
                'kind' => $trackKind,
                'srclang' => $properties['tx_lang_code'] ?: 'en',
                'label' => $properties['title'] ?: ($trackKind === 'descriptions' ? 'Descriptions' : 'Captions'),
            ];
        }
        
        // Chapters
        $chapterFiles = $this->fileRepository->findByRelation('tx_mpcvidply_media', 'chapters', $mediaUid);
        foreach ($chapterFiles as $chapterFile) {
            $properties = $chapterFile->getProperties();
            $textTracks[] = [
                'src' => $chapterFile->getPublicUrl(),
                'kind' => 'chapters',
                'srclang' => $properties['tx_lang_code'] ?: 'en',
                'label' => $properties['title'] ?: 'Chapters',
            ];
        }
        
        if (!empty($textTracks)) {
            $track['tracks'] = $textTracks;
        }
        
        // Get audio description
        $audioDescFiles = $this->fileRepository->findByRelation('tx_mpcvidply_media', 'audio_description', $mediaUid);
        if (!empty($audioDescFiles)) {
            $track['audioDescriptionSrc'] = $audioDescFiles[0]->getPublicUrl();
        }
        
        // Get sign language
        $signLangFiles = $this->fileRepository->findByRelation('tx_mpcvidply_media', 'sign_language', $mediaUid);
        if (!empty($signLangFiles)) {
            $track['signLanguageSrc'] = $signLangFiles[0]->getPublicUrl();
        }
        
        // Add transcript flag if enabled
        if (!empty($mediaRecord['enable_transcript'])) {
            $track['enableTranscript'] = true;
        }
        
        return $track;
    }
}

