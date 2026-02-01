<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\DataProcessing;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Mpc\MpcVidply\Service\PrivacySettingsService;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Utility\PathUtility;
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
    private readonly ResourceFactory $resourceFactory;
    private readonly ConnectionPool $connectionPool;
    private readonly PrivacySettingsService $privacySettingsService;
    private readonly ExtensionConfiguration $extensionConfiguration;

    /**
     * Micro-caches for this request to avoid repeated work in playlists.
     *
     * @var array<string, string>
     */
    private array $inferredMimeTypeByUrl = [];

    /**
     * @var array<int, string>
     */
    private array $publicUrlByFileReferenceUid = [];

    /**
     * @var array<int, string>
     */
    private array $mimeTypeByFileReferenceUid = [];

    /**
     * Prefetched file references by media uid and fieldname.
     *
     * @var array<int, array<string, FileReference[]>>
     */
    private array $fileReferencesByMediaUid = [];

    /**
     * Prefetched described-source file reference by original sys_file_reference uid.
     *
     * @var array<int, FileReference>
     */
    private array $describedSourceByFileReferenceUid = [];

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
        ?ResourceFactory $resourceFactory = null,
        ?ConnectionPool $connectionPool = null,
        ?PrivacySettingsService $privacySettingsService = null,
        ?ExtensionConfiguration $extensionConfiguration = null
    ) {
        $this->fileRepository = $fileRepository ?? GeneralUtility::makeInstance(FileRepository::class);
        $this->resourceFactory = $resourceFactory ?? GeneralUtility::makeInstance(ResourceFactory::class);
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->privacySettingsService = $privacySettingsService ?? GeneralUtility::makeInstance(PrivacySettingsService::class);
        $this->extensionConfiguration = $extensionConfiguration ?? GeneralUtility::makeInstance(ExtensionConfiguration::class);
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

        // Reset per-request micro-caches (DataProcessors can be reused in long-lived contexts)
        $this->inferredMimeTypeByUrl = [];
        $this->publicUrlByFileReferenceUid = [];
        $this->mimeTypeByFileReferenceUid = [];
        
        // Process options from checkbox field (bitmask)
        $options = (int)($data['tx_mpcvidply_options'] ?? 0);
        $playerOptions = [
            'autoplay' => (bool)($options & 1),
            'loop' => (bool)($options & 2),
            'muted' => (bool)($options & 4),
            'controls' => (bool)($options & 8),
            'captionsDefault' => (bool)($options & 16),
            'keyboard' => (bool)($options & 64),
            'autoAdvance' => (bool)($options & 256),
        ];

        // Opinionated defaults (not configurable via CE fields):
        // - Always responsive, never fixed px sizing.
        $playerOptions['responsive'] = true;
        // - Transcript is controlled per media record (tx_mpcvidply_media.enable_transcript).
        //   We'll set $playerOptions['transcript'] after building tracks.
        
        // Add other settings
        $playerOptions['volume'] = (float)($data['tx_mpcvidply_volume'] ?? 0.8);
        $playerOptions['playbackSpeed'] = (float)($data['tx_mpcvidply_playback_speed'] ?? 1.0);
        $playerOptions['language'] = $data['tx_mpcvidply_language'] ?? '';
        $playerOptions['defaultTranscriptLanguage'] = $playerOptions['language'];
        // UX defaults for VidPly library (applied per-player):
        // Hide speed control for HLS streams (audio + video).
        $playerOptions['hideSpeedForHls'] = true;
        // Performance defaults:
        // - Do not start fetching MP4/MP3/HLS until the user actually hits play.
        // - Keep autoplay behavior intact (autoplay must initialize and load immediately).
        $playerOptions['deferLoad'] = !$playerOptions['autoplay'];
        // If you want duration/timebar available before play, use 'metadata' here.
        // This will still perform a small network request, but much less than full media.
        $playerOptions['preload'] = 'metadata';
        // UX: if media is not started yet (deferLoad), show a short notice instead of
        // toggling audio-description/sign-language before playback.
        $playerOptions['requirePlaybackForAccessibilityToggles'] = $playerOptions['deferLoad'];
        
        // Audio description and sign language will be added after processing tracks
        
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

        // Prefetch all file references used by VidPly media records to avoid N+1 queries
        $mediaUids = array_values(array_unique(array_map(
            static fn(array $row): int => (int)($row['uid'] ?? 0),
            $mediaRecords
        )));
        $mediaUids = array_values(array_filter($mediaUids, static fn(int $uid): bool => $uid > 0));
        if ($mediaUids !== []) {
            $this->fileReferencesByMediaUid = $this->prefetchFileReferencesForMediaUids(
                $mediaUids,
                ['media_file', 'poster', 'captions', 'chapters', 'audio_description', 'sign_language']
            );
            $this->describedSourceByFileReferenceUid = $this->prefetchDescribedSourceFiles(
                $this->collectFileReferenceUidsForDescribedSource($mediaUids)
            );
        } else {
            $this->fileReferencesByMediaUid = [];
            $this->describedSourceByFileReferenceUid = [];
        }
        
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

        // Transcript should be per-track only:
        // enable transcript UI if at least one selected track opts into it.
        $playerOptions['transcript'] = false;
        foreach ($tracks as $t) {
            if (!empty($t['enableTranscript'])) {
                $playerOptions['transcript'] = true;
                break;
            }
        }
        // VidPly core shows the transcript control based on `options.transcriptButton`
        // (and whether captions/subtitles exist). To enforce "transcript per-track only",
        // we explicitly gate the transcript button via the per-media enable flag.
        $playerOptions['transcriptButton'] = $playerOptions['transcript'];
        
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

            // Per-media UI overrides (single item only)
            // Note: In playlists, controls belong to the player, not to individual tracks.
            if (!$isPlaylist && isset($mediaRecords[0]) && !empty($mediaRecords[0]['hide_speed_button'])) {
                $playerOptions['speedButton'] = false;
            }
            
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
                if (in_array($firstTrack['type'], ['youtube', 'vimeo', 'soundcloud', 'hls'], true)) {
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

                // Provide initial duration so UI can show it without loading media metadata
                if (!empty($firstTrack['duration'])) {
                    $playerOptions['initialDuration'] = (int)$firstTrack['duration'];
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
                    // Set display mode from media record (pip, main, or both)
                    $displayMode = $firstTrack['signLanguageDisplayMode'] ?? 'pip';
                    $playerOptions['signLanguageDisplayMode'] = in_array($displayMode, ['pip', 'main', 'both'], true) ? $displayMode : 'pip';
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
        
        // Load privacy settings for external services from database
        // Use language ID for multilingual support
        $privacySettings = [];
        if ($serviceType !== null) {
            $privacySettings[$serviceType] = $this->privacySettingsService->getSettingsForService($serviceType, $languageId);
        } elseif ($isPlaylist && $hasExternalMedia) {
            // For playlists with external media, load settings for all used services
            foreach ($externalServiceTypes as $extService) {
                $privacySettings[$extService] = $this->privacySettingsService->getSettingsForService($extService, $languageId);
            }
        }

        // Play button UI settings from site-wide extension configuration
        // (Admin Tools → Settings → Extension Configuration → mpc_vidply)
        // These apply to both the privacy consent overlay AND the video player's big play button.
        $playIconUrl = null;
        $playIconInlineSvg = null;
        $playButtonPosition = 'center';
        
        try {
            $extConf = $this->extensionConfiguration->get('mpc_vidply');
        } catch (\Throwable) {
            $extConf = [];
        }
        
        if (is_array($extConf)) {
            // Play icon (supports EXT: paths, absolute URLs, or relative public paths)
            $configuredIcon = trim((string)($extConf['playIcon'] ?? ''));
            if ($configuredIcon !== '') {
                if (str_starts_with($configuredIcon, 'EXT:')) {
                    // Normalize extension key: convert dashes to underscores (common pitfall)
                    $normalizedIcon = preg_replace_callback(
                        '/^EXT:([a-z0-9_-]+)\\//i',
                        static function (array $m): string {
                            return 'EXT:' . str_replace('-', '_', $m[1]) . '/';
                        },
                        $configuredIcon
                    ) ?: $configuredIcon;
                    
                    // Resolve EXT: path to a public web path (Composer mode compatible)
                    $webPath = PathUtility::getPublicResourceWebPath($normalizedIcon);
                    
                    if ($webPath !== '' && $webPath !== '/') {
                        $playIconUrl = $webPath;
                    } else {
                        // Fallback for legacy non-composer installs
                        $abs = GeneralUtility::getFileAbsFileName($normalizedIcon);
                        if ($abs !== '' && file_exists($abs)) {
                            $playIconUrl = PathUtility::getAbsoluteWebPath($abs);
                        }
                    }
                    
                    // If it's an SVG, also inline it for styling
                    if ($playIconUrl !== null) {
                        $playIconInlineSvg = $this->getInlineSvgFromExtPath($normalizedIcon);
                    }
                } else {
                    // Absolute URL or relative public path
                    $playIconUrl = $configuredIcon;
                }
            }
            
            // Play button position - normalize to handle both value format (left-bottom) and label format (Left bottom)
            $configuredPosition = strtolower(str_replace(' ', '-', trim((string)($extConf['playPosition'] ?? ''))));
            $allowedPositions = ['center', 'left-top', 'right-top', 'left-bottom', 'right-bottom'];
            if (in_array($configuredPosition, $allowedPositions, true)) {
                $playButtonPosition = $configuredPosition;
            }
        }
        
        // CSS-based icon system (opt-in via extension configuration)
        $useCssIcons = !empty($extConf['useCssIcons']);
        
        // Determine which assets are needed for conditional loading
        // For mixed playlists: always use VidPly with playlist-integrated privacy consent
        $needsPrivacyLayer = $serviceType !== null || ($isPlaylist && $hasExternalMedia);
        $needsVidPlay = $isPlaylist || $serviceType === null; // VidPly for playlists and local media
        $needsPlaylist = $isPlaylist || $needsVidPlay; // Playlist OR native player
        
        // Check if HLS is needed
        $needsHLS = false;
        foreach ($tracks as $track) {
            if (in_array($track['type'] ?? '', ['hls', 'application/x-mpegurl', 'application/vnd.apple.mpegurl'], true)) {
                $needsHLS = true;
                break;
            }
        }

        // Derive effective media type for template rendering (<audio> vs <video>).
        // This is important for stream types (e.g. audio HLS) which should not render a <video> element.
        $resolvedMediaType = $mediaType ?? 'video';
        if (!empty($tracks)) {
            $hasVideoTrack = false;
            $hasAudioTrack = false;
            foreach ($tracks as $track) {
                $type = (string)($track['type'] ?? '');
                if ($type === '') {
                    continue;
                }

                // Explicit hint from processing (preferred)
                $kind = (string)($track['kind'] ?? '');
                if ($kind === 'video') {
                    $hasVideoTrack = true;
                    continue;
                }
                if ($kind === 'audio') {
                    $hasAudioTrack = true;
                    continue;
                }

                // MIME-based detection (local files and inferred external media)
                if (str_starts_with($type, 'video/')) {
                    $hasVideoTrack = true;
                } elseif (str_starts_with($type, 'audio/')) {
                    $hasAudioTrack = true;
                } elseif (in_array($type, ['youtube', 'vimeo', 'hls'], true)) {
                    $hasVideoTrack = true;
                } elseif ($type === 'soundcloud') {
                    $hasAudioTrack = true;
                }
            }

            if ($hasAudioTrack && !$hasVideoTrack) {
                $resolvedMediaType = 'audio';
            }
        }
        
        // Build processed data with template compatibility
        $vidplyData = [
            'mediaType' => $resolvedMediaType,
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
            'options' => $playerOptions,
            'languageSelection' => $playerOptions['language'] ?? '',
            'uniqueId' => 'vidply-' . $data['uid'],
            'playlistData' => $playlistData,
            'tracks' => $tracks,
            'privacySettings' => $privacySettings, // Privacy layer settings for external services
            // Play button UI (applies to both privacy layer and video player overlay)
            'privacyPlayIconUrl' => $playIconUrl,
            'privacyPlayIconInlineSvg' => $playIconInlineSvg,
            'privacyPlayButtonPosition' => $playButtonPosition,
            // CSS-based icon system (adds .vidply-use-css-icons class to wrapper)
            'useCssIcons' => $useCssIcons,
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

    private function getInlineSvgFromFileReference(FileReference $fileReference): ?string
    {
        try {
            $originalFile = $fileReference->getOriginalFile();
        } catch (\Throwable) {
            return null;
        }

        if (strtolower((string)$originalFile->getExtension()) !== 'svg') {
            return null;
        }

        try {
            $localPath = $originalFile->getForLocalProcessing(false);
        } catch (\Throwable) {
            return null;
        }

        return $this->loadAndSanitizeSvgFromAbsolutePath((string)$localPath);
    }

    private function getInlineSvgFromExtPath(string $extPathOrUrl): ?string
    {
        $value = trim($extPathOrUrl);
        if ($value === '' || !str_starts_with($value, 'EXT:')) {
            return null;
        }
        if (strtolower((string)pathinfo($value, PATHINFO_EXTENSION)) !== 'svg') {
            return null;
        }

        // Handle common pitfall: "-" instead of "_" in extension key
        $normalized = preg_replace_callback(
            '/^EXT:([a-z0-9-]+)\\//i',
            static function (array $m): string {
                return 'EXT:' . str_replace('-', '_', $m[1]) . '/';
            },
            $value
        ) ?: $value;

        $abs = GeneralUtility::getFileAbsFileName($normalized);
        if (!$abs) {
            // try raw as a fallback
            $abs = GeneralUtility::getFileAbsFileName($value);
        }

        return $abs ? $this->loadAndSanitizeSvgFromAbsolutePath((string)$abs) : null;
    }

    private function loadAndSanitizeSvgFromAbsolutePath(string $absolutePath): ?string
    {
        $absolutePath = trim($absolutePath);
        if ($absolutePath === '' || !is_file($absolutePath)) {
            return null;
        }

        // Avoid huge inline payloads
        $maxBytes = 256 * 1024;
        $size = @filesize($absolutePath);
        if (is_int($size) && $size > $maxBytes) {
            return null;
        }

        $raw = @file_get_contents($absolutePath);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        // Prevent network access and ignore external entities
        $loaded = @$dom->loadXML($raw, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        if (!$loaded) {
            return null;
        }

        $svg = $dom->getElementsByTagName('svg')->item(0);
        if (!$svg instanceof \DOMElement) {
            return null;
        }

        // Remove potentially dangerous elements
        foreach (['script', 'foreignObject'] as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            // NodeList is live; remove from end
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                if ($node && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $xpath = new \DOMXPath($dom);

        // Remove inline event handlers (onclick, onload, ...) and sanitize href-like attributes.
        // We intentionally avoid XPath namespace prefixes (e.g. xlink:href) to prevent warnings
        // when SVGs declare no xlink namespace.
        $attrs = $xpath->query('//@*');
        if ($attrs instanceof \DOMNodeList) {
            for ($i = $attrs->length - 1; $i >= 0; $i--) {
                $attr = $attrs->item($i);
                if (!$attr instanceof \DOMAttr) {
                    continue;
                }
                $name = strtolower($attr->name);
                if (str_starts_with($name, 'on')) {
                    $attr->ownerElement?->removeAttributeNode($attr);
                    continue;
                }

                // Sanitize javascript: URLs in href-like attributes (href, xlink:href, ...)
                if ($attr->localName === 'href') {
                    $val = trim((string)$attr->value);
                    if (stripos($val, 'javascript:') === 0) {
                        $attr->ownerElement?->removeAttributeNode($attr);
                    }
                }
            }
        }

        // Add our marker class, but avoid colliding with VidPly's own `.vidply-play-overlay`
        // (that class has absolute positioning etc. for the main player overlay).
        $existingClass = trim((string)$svg->getAttribute('class'));
        $parts = preg_split('/\s+/', $existingClass) ?: [];
        $parts = array_values(array_filter($parts, static fn(string $c): bool => $c !== '' && $c !== 'vidply-play-overlay'));
        $parts[] = 'mpc-vidply-custom-play-icon';
        $svg->setAttribute('class', implode(' ', array_values(array_unique($parts))));
        $svg->setAttribute('aria-hidden', 'true');
        $svg->setAttribute('focusable', 'false');
        $svg->removeAttribute('width');
        $svg->removeAttribute('height');

        return $dom->saveXML($svg) ?: null;
    }

    /**
     * Get media records associated with content element via MM table
     * Respects language translations and falls back to default language if needed
     */
    protected function getMediaRecords(int $contentUid, int $languageId = 0): array
    {
        // Step 1: Get MM relations for this content element
        $mmQueryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_content_media_mm');
        $mmRelations = $mmQueryBuilder
            ->select('uid_foreign', 'sorting')
            ->from('tx_mpcvidply_content_media_mm')
            ->where(
                $mmQueryBuilder->expr()->eq(
                    'uid_local',
                    $mmQueryBuilder->createNamedParameter($contentUid, Connection::PARAM_INT)
                )
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($mmRelations === []) {
            return [];
        }

        $referencedUids = array_values(array_unique(array_map(
            static fn(array $row): int => (int)($row['uid_foreign'] ?? 0),
            $mmRelations
        )));
        $referencedUids = array_values(array_filter($referencedUids, static fn(int $v): bool => $v > 0));
        if ($referencedUids === []) {
            return [];
        }

        // Step 2: Load all referenced records (full rows) in one query
        $mediaQueryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_media');
        $referencedRecords = $mediaQueryBuilder
            ->select('*')
            ->from('tx_mpcvidply_media')
            ->where(
                $mediaQueryBuilder->expr()->in(
                    'uid',
                    $mediaQueryBuilder->createNamedParameter($referencedUids, Connection::PARAM_INT_ARRAY)
                ),
                $mediaQueryBuilder->expr()->eq('deleted', $mediaQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $mediaQueryBuilder->expr()->eq('hidden', $mediaQueryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ($referencedRecords === []) {
            return [];
        }

        $referencedByUid = [];
        foreach ($referencedRecords as $row) {
            $uid = (int)($row['uid'] ?? 0);
            if ($uid > 0) {
                $referencedByUid[$uid] = $row;
            }
        }

        // Resolve default-language uid for each referenced uid
        $defaultUids = [];
        foreach ($referencedUids as $uid) {
            if (!isset($referencedByUid[$uid])) {
                continue;
            }
            $row = $referencedByUid[$uid];
            $refLang = (int)($row['sys_language_uid'] ?? 0);
            $defaultUid = $refLang === 0 ? $uid : (int)($row['l10n_parent'] ?? 0);
            if ($defaultUid > 0) {
                $defaultUids[] = $defaultUid;
            }
        }
        $defaultUids = array_values(array_unique($defaultUids));
        if ($defaultUids === []) {
            return [];
        }

        // Step 3: Load default-language records for all default uids (single query)
        $defaultQueryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_media');
        $defaultRecords = $defaultQueryBuilder
            ->select('*')
            ->from('tx_mpcvidply_media')
            ->where(
                $defaultQueryBuilder->expr()->in(
                    'uid',
                    $defaultQueryBuilder->createNamedParameter($defaultUids, Connection::PARAM_INT_ARRAY)
                ),
                $defaultQueryBuilder->expr()->eq('sys_language_uid', $defaultQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $defaultQueryBuilder->expr()->eq('deleted', $defaultQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $defaultQueryBuilder->expr()->eq('hidden', $defaultQueryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $defaultByUid = [];
        foreach ($defaultRecords as $row) {
            $uid = (int)($row['uid'] ?? 0);
            if ($uid > 0) {
                $defaultByUid[$uid] = $row;
            }
        }

        // Step 4 (optional): Load translated records for all default uids (single query)
        $translatedByParent = [];
        if ($languageId > 0) {
            $translatedQueryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_media');
            $translatedRecords = $translatedQueryBuilder
                ->select('*')
                ->from('tx_mpcvidply_media')
                ->where(
                    $translatedQueryBuilder->expr()->in(
                        'l10n_parent',
                        $translatedQueryBuilder->createNamedParameter($defaultUids, Connection::PARAM_INT_ARRAY)
                    ),
                    $translatedQueryBuilder->expr()->eq(
                        'sys_language_uid',
                        $translatedQueryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                    ),
                    $translatedQueryBuilder->expr()->eq('deleted', $translatedQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $translatedQueryBuilder->expr()->eq('hidden', $translatedQueryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                )
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($translatedRecords as $row) {
                $parent = (int)($row['l10n_parent'] ?? 0);
                if ($parent > 0) {
                    $translatedByParent[$parent] = $row;
                }
            }
        }

        // Step 5: Build ordered list with correct language fallback, preserving MM sorting
        $resultRecords = [];
        foreach ($mmRelations as $mmRelation) {
            $mediaUid = (int)($mmRelation['uid_foreign'] ?? 0);
            $sorting = (int)($mmRelation['sorting'] ?? 0);
            if ($mediaUid <= 0 || !isset($referencedByUid[$mediaUid])) {
                continue;
            }

            $referenced = $referencedByUid[$mediaUid];
            $referencedLanguageId = (int)($referenced['sys_language_uid'] ?? 0);
            $defaultLanguageUid = $referencedLanguageId === 0
                ? $mediaUid
                : (int)($referenced['l10n_parent'] ?? 0);

            $selected = null;
            if ($languageId > 0) {
                if ($referencedLanguageId === $languageId) {
                    $selected = $referenced;
                } elseif ($defaultLanguageUid > 0 && isset($translatedByParent[$defaultLanguageUid])) {
                    $selected = $translatedByParent[$defaultLanguageUid];
                } elseif ($defaultLanguageUid > 0 && isset($defaultByUid[$defaultLanguageUid])) {
                    $selected = $defaultByUid[$defaultLanguageUid];
                }
            } else {
                if ($referencedLanguageId === 0) {
                    $selected = $referenced;
                } elseif ($defaultLanguageUid > 0 && isset($defaultByUid[$defaultLanguageUid])) {
                    $selected = $defaultByUid[$defaultLanguageUid];
                }
            }

            if (is_array($selected)) {
                $selected['sorting'] = $sorting;
                $resultRecords[] = $selected;
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

        // Per-track UI overrides (consumed by PlaylistInit.js / templates)
        if (!empty($mediaRecord['hide_speed_button'])) {
            $track['hideSpeedButton'] = true;
        }
        
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
                $mediaFiles = $this->getFileReferencesForMedia($mediaUid, 'media_file');
                if (empty($mediaFiles)) {
                    return null; // Skip if no file
                }
                $mediaFile = $mediaFiles[0];
                // For YouTube/Vimeo, the file extension is 'youtube' or 'vimeo'
                // TYPO3's online media helpers store the URL in the file's properties
                $track['src'] = $mediaFile->getPublicUrl();
                $track['type'] = $mediaType;
                $track['kind'] = 'video';
                break;
                
            case 'soundcloud':
                // SoundCloud: file-based online media container file (.soundcloud)
                $mediaFiles = $this->getFileReferencesForMedia($mediaUid, 'media_file');
                if (empty($mediaFiles)) {
                    return null; // Skip if no file
                }
                $mediaFile = $mediaFiles[0];
                $track['src'] = $this->getPublicUrlCached($mediaFile);
                $track['type'] = 'soundcloud';
                $track['kind'] = 'audio';
                break;
                
            case 'hls':
                // File-based online media container file (.hls) or playlist file (.m3u8)
                $mediaFiles = $this->getFileReferencesForMedia($mediaUid, 'media_file');
                if (empty($mediaFiles)) {
                    return null; // Skip if no file
                }
                $mediaFile = $mediaFiles[0];
                $track['src'] = $this->getPublicUrlCached($mediaFile);
                $track['type'] = $mediaType;
                $hlsKind = strtolower((string)($mediaRecord['hls_kind'] ?? 'video'));
                $track['kind'] = in_array($hlsKind, ['audio', 'video'], true) ? $hlsKind : 'video';
                break;
                
            case 'video':
            case 'audio':
                // File-based media - can have multiple files (e.g., MP4 and WebM)
                $mediaFiles = $this->getFileReferencesForMedia($mediaUid, 'media_file');
                if (empty($mediaFiles)) {
                    return null; // Skip if no file
                }
                $track['kind'] = $mediaType;
                // If multiple files, create sources array for HTML5 video/audio element
                if (count($mediaFiles) > 1) {
                    $track['sources'] = [];
                    foreach ($mediaFiles as $mediaFile) {
                        $publicUrl = $this->getPublicUrlCached($mediaFile);
                        $mimeType = $this->getMimeTypeCached($mediaFile);
                        // Online media container files (externalaudio/externalvideo) are stored as text/plain in FAL
                        // but the HTML5 <source type="..."> must match the actual remote media type.
                        if (in_array($mediaFile->getExtension(), ['externalaudio', 'externalvideo', 'hls', 'm3u8'], true)) {
                            $mimeType = $this->inferMimeTypeFromUrlCached($publicUrl, $mimeType);
                        }
                        $track['sources'][] = [
                            'src' => $publicUrl,
                            'type' => $mimeType,
                            'label' => 'Default', // Label for quality/format selection
                        ];
                    }
                    // Use first file as primary src for backward compatibility
                    $track['src'] = $track['sources'][0]['src'];
                    $track['type'] = $track['sources'][0]['type'];
                } else {
                    // Single file - use simple src
                    $mediaFile = $mediaFiles[0];
                    $track['src'] = $this->getPublicUrlCached($mediaFile);
                    $track['type'] = $this->getMimeTypeCached($mediaFile);
                    if (in_array($mediaFile->getExtension(), ['externalaudio', 'externalvideo', 'hls', 'm3u8'], true)) {
                        $track['type'] = $this->inferMimeTypeFromUrlCached((string)$track['src'], (string)$track['type']);
                    }
                }
                break;
                
            default:
                return null; // Unknown media type
        }
        
        // Get poster/thumbnail
        $posterFiles = $this->getFileReferencesForMedia($mediaUid, 'poster');
        if (!empty($posterFiles)) {
            $track['poster'] = $posterFiles[0]->getPublicUrl();
        }
        
        // Get captions and chapters (text tracks)
        $textTracks = [];
        
        // Captions - can be captions or descriptions
        $captionFiles = $this->getFileReferencesForMedia($mediaUid, 'captions');
        foreach ($captionFiles as $captionFile) {
            $properties = $captionFile->getProperties();
            $trackKind = $properties['tx_track_kind'] ?: 'captions';
            // Support both 'captions' and 'descriptions' track kinds
            $trackData = [
                'src' => $captionFile->getPublicUrl(),
                'kind' => $trackKind,
                'srclang' => $properties['tx_lang_code'] ?: 'en',
                'label' => $properties['title'] ?: ($trackKind === 'descriptions' ? 'Descriptions' : 'Captions'),
            ];
            
            // Check for described source file (audio description version of this track)
            $descSrcUrl = $this->getDescribedSourceUrl($captionFile);
            if ($descSrcUrl !== null) {
                $trackData['describedSrc'] = $descSrcUrl;
            }
            
            $textTracks[] = $trackData;
        }
        
        // Chapters
        $chapterFiles = $this->getFileReferencesForMedia($mediaUid, 'chapters');
        foreach ($chapterFiles as $chapterFile) {
            $properties = $chapterFile->getProperties();
            $trackData = [
                'src' => $chapterFile->getPublicUrl(),
                'kind' => 'chapters',
                'srclang' => $properties['tx_lang_code'] ?: 'en',
                'label' => $properties['title'] ?: 'Chapters',
            ];
            
            // Check for described source file (audio description version of this track)
            $descSrcUrl = $this->getDescribedSourceUrl($chapterFile);
            if ($descSrcUrl !== null) {
                $trackData['describedSrc'] = $descSrcUrl;
            }
            
            $textTracks[] = $trackData;
        }
        
        if (!empty($textTracks)) {
            $track['tracks'] = $textTracks;
        }
        
        // Get audio description
        $audioDescFiles = $this->getFileReferencesForMedia($mediaUid, 'audio_description');
        if (!empty($audioDescFiles)) {
            $track['audioDescriptionSrc'] = $audioDescFiles[0]->getPublicUrl();
        }
        
        // Get sign language
        $signLangFiles = $this->getFileReferencesForMedia($mediaUid, 'sign_language');
        if (!empty($signLangFiles)) {
            $track['signLanguageSrc'] = $signLangFiles[0]->getPublicUrl();
            // Add display mode (pip, main, or both)
            $track['signLanguageDisplayMode'] = $mediaRecord['sign_language_display_mode'] ?? 'pip';
        }
        
        // Add transcript flag if enabled
        if (!empty($mediaRecord['enable_transcript'])) {
            $track['enableTranscript'] = true;
        }
        
        return $track;
    }

    protected function inferMimeTypeFromUrlCached(string $url, string $fallbackMimeType = ''): string
    {
        $cacheKey = $url . '|' . $fallbackMimeType;
        if (isset($this->inferredMimeTypeByUrl[$cacheKey])) {
            return $this->inferredMimeTypeByUrl[$cacheKey];
        }

        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));

        $mimeType = match ($ext) {
            // audio
            'mp3' => 'audio/mpeg',
            'ogg', 'oga' => 'audio/ogg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            // streaming playlists
            'm3u8' => 'application/vnd.apple.mpegurl',
            // video
            'mp4' => 'video/mp4',
            'm4v' => 'video/x-m4v',
            'webm' => 'video/webm',
            'ogv' => 'video/ogg',
            default => $fallbackMimeType !== '' ? $fallbackMimeType : 'application/octet-stream',
        };

        $this->inferredMimeTypeByUrl[$cacheKey] = $mimeType;
        return $mimeType;
    }

    protected function getPublicUrlCached(FileReference $fileReference): string
    {
        $uid = $fileReference->getUid();
        if ($uid > 0 && isset($this->publicUrlByFileReferenceUid[$uid])) {
            return $this->publicUrlByFileReferenceUid[$uid];
        }
        $publicUrl = (string)$fileReference->getPublicUrl();
        if ($uid > 0) {
            $this->publicUrlByFileReferenceUid[$uid] = $publicUrl;
        }
        return $publicUrl;
    }

    protected function getMimeTypeCached(FileReference $fileReference): string
    {
        $uid = $fileReference->getUid();
        if ($uid > 0 && isset($this->mimeTypeByFileReferenceUid[$uid])) {
            return $this->mimeTypeByFileReferenceUid[$uid];
        }
        $mimeType = (string)$fileReference->getMimeType();
        if ($uid > 0) {
            $this->mimeTypeByFileReferenceUid[$uid] = $mimeType;
        }
        return $mimeType;
    }

    /**
     * Get the public URL for the described source file of a track
     * 
     * The described source is an alternative VTT file to use when audio description mode is enabled.
     * This is stored as a file reference in the tx_desc_src_file field of the track's file reference.
     */
    protected function getDescribedSourceUrl(FileReference $trackFileReference): ?string
    {
        // Get the UID of the file reference record
        $fileReferenceUid = $trackFileReference->getUid();
        if ($fileReferenceUid > 0 && isset($this->describedSourceByFileReferenceUid[$fileReferenceUid])) {
            return $this->describedSourceByFileReferenceUid[$fileReferenceUid]->getPublicUrl();
        }
        
        return null;
    }

    /**
     * @return FileReference[]
     */
    protected function getFileReferencesForMedia(int $mediaUid, string $fieldName): array
    {
        if ($mediaUid <= 0) {
            return [];
        }
        if (isset($this->fileReferencesByMediaUid[$mediaUid][$fieldName])) {
            return $this->fileReferencesByMediaUid[$mediaUid][$fieldName];
        }
        // Fallback (should not happen in FE, but keeps behavior safe)
        return $this->fileRepository->findByRelation('tx_mpcvidply_media', $fieldName, $mediaUid);
    }

    /**
     * Bulk prefetch of file references for VidPly media records.
     *
     * @param int[] $mediaUids
     * @param string[] $fieldNames
     * @return array<int, array<string, FileReference[]>>
     */
    protected function prefetchFileReferencesForMediaUids(array $mediaUids, array $fieldNames): array
    {
        $result = [];
        if ($mediaUids === [] || $fieldNames === []) {
            return $result;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        // DataProcessor runs in FE; apply FE restrictions (deleted/hidden/start/end etc.)
        $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $rows = $queryBuilder
            ->select('uid', 'uid_foreign', 'fieldname')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('tx_mpcvidply_media')),
                $queryBuilder->expr()->in('fieldname', $queryBuilder->createNamedParameter($fieldNames, Connection::PARAM_STR_ARRAY)),
                $queryBuilder->expr()->in('uid_foreign', $queryBuilder->createNamedParameter($mediaUids, Connection::PARAM_INT_ARRAY))
            )
            ->orderBy('uid_foreign', 'ASC')
            ->addOrderBy('fieldname', 'ASC')
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

            $result[$uidForeign][$fieldName] ??= [];
            $result[$uidForeign][$fieldName][] = $fileReference;
        }

        // Ensure all requested fields exist as arrays to simplify access
        foreach ($mediaUids as $mediaUid) {
            $result[$mediaUid] ??= [];
            foreach ($fieldNames as $fieldName) {
                $result[$mediaUid][$fieldName] ??= [];
            }
        }

        return $result;
    }

    /**
     * Collect sys_file_reference uids for captions/chapters to allow bulk lookup of described-source relations.
     *
     * @param int[] $mediaUids
     * @return int[]
     */
    protected function collectFileReferenceUidsForDescribedSource(array $mediaUids): array
    {
        $uids = [];
        foreach ($mediaUids as $mediaUid) {
            foreach (['captions', 'chapters'] as $field) {
                foreach ($this->fileReferencesByMediaUid[$mediaUid][$field] ?? [] as $fileReference) {
                    $uid = $fileReference->getUid();
                    if ($uid > 0) {
                        $uids[] = $uid;
                    }
                }
            }
        }
        return array_values(array_unique($uids));
    }

    /**
     * Prefetch "described source" file references (sys_file_reference.tx_desc_src_file) for a set of source file reference uids.
     *
     * @param int[] $sourceFileReferenceUids
     * @return array<int, FileReference> map: source sys_file_reference uid -> first described-source FileReference
     */
    protected function prefetchDescribedSourceFiles(array $sourceFileReferenceUids): array
    {
        $result = [];
        if ($sourceFileReferenceUids === []) {
            return $result;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $rows = $queryBuilder
            ->select('uid', 'uid_foreign')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('sys_file_reference')),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter('tx_desc_src_file')),
                $queryBuilder->expr()->in(
                    'uid_foreign',
                    $queryBuilder->createNamedParameter($sourceFileReferenceUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->orderBy('uid_foreign', 'ASC')
            ->addOrderBy('sorting_foreign', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $uid = (int)($row['uid'] ?? 0);
            $uidForeign = (int)($row['uid_foreign'] ?? 0);
            if ($uid <= 0 || $uidForeign <= 0) {
                continue;
            }
            // Keep first (lowest sorting_foreign)
            if (isset($result[$uidForeign])) {
                continue;
            }
            try {
                $result[$uidForeign] = $this->resourceFactory->getFileReferenceObject($uid);
            } catch (ResourceDoesNotExistException) {
                // ignore
            }
        }

        return $result;
    }
}

