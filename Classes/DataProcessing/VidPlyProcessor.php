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
            'autoplay' => (bool)($options & 1), // Explicitly false when bit 1 is not set
            'loop' => (bool)($options & 2),
            'muted' => (bool)($options & 4),
            'controls' => (bool)($options & 8),
            'captionsDefault' => (bool)($options & 16),
            'transcript' => (bool)($options & 32),
            'keyboard' => (bool)($options & 64),
            'responsive' => (bool)($options & 128),
            'autoAdvance' => (bool)($options & 256),
        ];
        
        // Ensure autoplay is explicitly false when not enabled (important for VidPly)
        if (!($options & 1)) {
            $playerOptions['autoplay'] = false;
        }
        
        // Add other settings
        $playerOptions['volume'] = (float)($data['tx_mpcvidply_volume'] ?? 0.8);
        $playerOptions['playbackSpeed'] = (float)($data['tx_mpcvidply_playback_speed'] ?? 1.0);
        $playerOptions['language'] = $data['tx_mpcvidply_language'] ?? '';
        $playerOptions['defaultTranscriptLanguage'] = $playerOptions['language'];
        
        // Audio description and sign language will be added after processing tracks
        
        // Dimensions
        $width = (int)($data['tx_mpcvidply_width'] ?? 800);
        $height = (int)($data['tx_mpcvidply_height'] ?? 450);
        
        // Get media items from MM table
        $mediaRecords = $this->getMediaRecords((int)$data['uid']);
        
        // Process media records into tracks
        $tracks = [];
        $mediaType = null;
        foreach ($mediaRecords as $mediaRecord) {
            $track = $this->processMediaRecord($mediaRecord);
            if ($track) {
                $tracks[] = $track;
                // Set media type from first item (simplified: video or audio)
                if ($mediaType === null) {
                    $recordType = $mediaRecord['media_type'];
                    $mediaType = ($recordType === 'audio') ? 'audio' : 'video';
                }
            }
        }
        
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
        $serviceType = null;
        if (!empty($tracks)) {
            $firstTrackType = $tracks[0]['type'] ?? null;
            if (in_array($firstTrackType, ['youtube', 'vimeo', 'soundcloud'])) {
                $serviceType = $firstTrackType;
            }
        }
        
        // Determine which assets are needed for conditional loading
        $needsPrivacyLayer = $serviceType !== null; // YouTube, Vimeo, SoundCloud
        $needsVidPlay = !$needsPrivacyLayer; // Native player (not external services)
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
            'serviceType' => $serviceType, // For privacy layer detection
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
     */
    protected function getMediaRecords(int $contentUid): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('tx_mpcvidply_media');
        
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
        
        // Add duration if available
        if (!empty($mediaRecord['duration'])) {
            $track['duration'] = (int)$mediaRecord['duration'];
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
