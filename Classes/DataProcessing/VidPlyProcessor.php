<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\DataProcessing;

use Mpc\MpcVidply\Enums\MediaType;
use Mpc\MpcVidply\Enums\RenderMode;
use Mpc\MpcVidply\Repository\MediaRepository;
use Mpc\MpcVidply\Service\PrivacySettingsService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

/**
 * DataProcessor for VidPly Player
 *
 * Processes content element data and prepares it for the Fluid template.
 * Works with standalone media records from tx_mpcvidply_media table.
 *
 * Uses constructor injection with fallback for TYPO3 13/14 compatibility.
 * DataProcessors are instantiated via GeneralUtility::makeInstance() from TypoScript,
 * so we need optional parameters with service locator fallback.
 */
class VidPlyProcessor implements DataProcessorInterface
{
    private const OPT_AUTOPLAY = 1;
    private const OPT_LOOP = 2;
    private const OPT_MUTED = 4;
    private const OPT_CONTROLS = 8;
    private const OPT_CAPTIONS_DEFAULT = 16;
    private const OPT_KEYBOARD = 64;
    private const OPT_AUTO_ADVANCE = 256;

    private readonly FileRepository $fileRepository;
    private readonly ResourceFactory $resourceFactory;
    private readonly ConnectionPool $connectionPool;
    private readonly PrivacySettingsService $privacySettingsService;
    private readonly ExtensionConfiguration $extensionConfiguration;
    private readonly MediaRepository $mediaRepository;

    /** @var array<string, string> */
    private array $inferredMimeTypeByUrl = [];

    /** @var array<int, string> */
    private array $publicUrlByFileReferenceUid = [];

    /** @var array<int, string> */
    private array $mimeTypeByFileReferenceUid = [];

    /** @var array<int, array<string, FileReference[]>> */
    private array $fileReferencesByMediaUid = [];

    /** @var array<int, FileReference> */
    private array $describedSourceByFileReferenceUid = [];

    /**
     * Parameters are optional to support GeneralUtility::makeInstance() calls.
     * When autowired (e.g., in tests or TYPO3 14), dependencies are injected.
     */
    public function __construct(
        ?FileRepository $fileRepository = null,
        ?ResourceFactory $resourceFactory = null,
        ?ConnectionPool $connectionPool = null,
        ?PrivacySettingsService $privacySettingsService = null,
        ?ExtensionConfiguration $extensionConfiguration = null,
        ?MediaRepository $mediaRepository = null
    ) {
        $this->fileRepository = $fileRepository ?? GeneralUtility::makeInstance(FileRepository::class);
        $this->resourceFactory = $resourceFactory ?? GeneralUtility::makeInstance(ResourceFactory::class);
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->privacySettingsService = $privacySettingsService ?? GeneralUtility::makeInstance(PrivacySettingsService::class);
        $this->extensionConfiguration = $extensionConfiguration ?? GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $this->mediaRepository = $mediaRepository ?? GeneralUtility::makeInstance(MediaRepository::class);
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
        $contentUid = (int)$data['uid'];
        $l10nParent = (int)($data['l18n_parent'] ?? $data['l10n_parent'] ?? 0);
        // MM uid_local: try translated CE first, then default (see MediaRepository::findByContentUid)
        $mediaRecords = $this->mediaRepository->findByContentUid(
            $contentUid,
            $languageId,
            $l10nParent > 0 ? $l10nParent : 0
        );

        $processedData['vidply'] = $this->assembleForMediaRecords($mediaRecords, $data, $request, $languageId);

        return $processedData;
    }

    /**
     * Build a full "vidply" template data array for a pre-resolved list of media records.
     *
     * This lets other data processors (e.g. a detail page resolver) reuse the entire
     * player assembly pipeline without requiring a tt_content MM relation.
     *
     * @param list<array<string, mixed>> $mediaRecords
     * @param array<string, mixed>       $data          The owning content element / pseudo-record
     * @param ?int                       $languageIdOverride  When null, resolved from request
     * @return array<string, mixed>
     */
    public function assembleForMediaRecords(
        array $mediaRecords,
        array $data,
        ServerRequestInterface $request,
        ?int $languageIdOverride = null
    ): array {
        $this->resetCaches();

        $playerOptions = $this->buildPlayerOptions($data);
        $languageId = $languageIdOverride ?? $this->resolveLanguageId($request, $data);

        $this->prefetchRelatedFiles($mediaRecords);

        $siteDefaultLanguageCode = $this->resolveSiteDefaultLanguageCode($request);
        $trackResult = $this->buildTracksResult($mediaRecords, $siteDefaultLanguageCode);
        $this->applyTrackDependentOptions($playerOptions, $trackResult, $mediaRecords);

        $playlistResult = $this->buildPlaylistData($trackResult, $playerOptions);
        $playlistData = $playlistResult['playlistData'];
        $playerOptions = array_merge($playerOptions, $playlistResult['optionOverrides']);

        $singleTrackResult = $this->extractSingleTrackData($trackResult, $siteDefaultLanguageCode);
        $singleTrackData = $singleTrackResult['trackData'];
        $playerOptions = array_merge($playerOptions, $singleTrackResult['optionOverrides']);

        $serviceType = $this->resolveServiceType($trackResult);
        $privacySettings = $this->loadPrivacySettings($serviceType, $trackResult, $languageId, $request);
        $uiConfig = $this->resolveExtensionConfig();
        $playerOptions['theme'] = $uiConfig['theme'];

        $resolvedMediaType = $this->resolveEffectiveMediaType($trackResult);
        $renderMode = $this->determineRenderMode($serviceType, $trackResult, $resolvedMediaType);
        $assetFlags = $this->resolveAssetFlags($serviceType, $trackResult);

        return $this->assembleTemplateData(
            $data, $playerOptions, $trackResult, $singleTrackData,
            $playlistData, $serviceType, $privacySettings, $uiConfig,
            $resolvedMediaType, $renderMode, $assetFlags
        );
    }

    // -----------------------------------------------------------------------
    // Decomposed phases of process()
    // -----------------------------------------------------------------------

    private function resetCaches(): void
    {
        $this->inferredMimeTypeByUrl = [];
        $this->publicUrlByFileReferenceUid = [];
        $this->mimeTypeByFileReferenceUid = [];
        $this->fileReferencesByMediaUid = [];
        $this->describedSourceByFileReferenceUid = [];
    }

    private function buildPlayerOptions(array $data): array
    {
        $bits = (int)($data['tx_mpcvidply_options'] ?? 0);
        $playerOptions = [
            'autoplay' => (bool)($bits & self::OPT_AUTOPLAY),
            'loop' => (bool)($bits & self::OPT_LOOP),
            'muted' => (bool)($bits & self::OPT_MUTED),
            'controls' => (bool)($bits & self::OPT_CONTROLS),
            'captionsDefault' => (bool)($bits & self::OPT_CAPTIONS_DEFAULT),
            'keyboard' => (bool)($bits & self::OPT_KEYBOARD),
            'autoAdvance' => (bool)($bits & self::OPT_AUTO_ADVANCE),
        ];

        $playerOptions['responsive'] = true;
        $playerOptions['volume'] = (float)($data['tx_mpcvidply_volume'] ?? 0.8);
        $playerOptions['playbackSpeed'] = (float)($data['tx_mpcvidply_playback_speed'] ?? 1.0);
        $playerOptions['language'] = $data['tx_mpcvidply_language'] ?? '';
        $playerOptions['defaultTranscriptLanguage'] = $playerOptions['language'];
        $playerOptions['deferLoad'] = !$playerOptions['autoplay'];
        $playerOptions['preload'] = 'metadata';
        $playerOptions['requirePlaybackForAccessibilityToggles'] = $playerOptions['deferLoad'];

        return $playerOptions;
    }

    /**
     * Resolve the frontend language ID from the request or content element.
     * Uses toArray() to avoid extension scanner false positive on getLanguageId().
     */
    private function resolveLanguageId(ServerRequestInterface $request, array $data): int
    {
        $languageId = 0;
        $language = $request->getAttribute('language');
        if ($language !== null) {
            $languageId = (int)$language->toArray()['languageId'];
        }

        if ($languageId === 0 && isset($data['sys_language_uid']) && (int)$data['sys_language_uid'] > 0) {
            $languageId = (int)$data['sys_language_uid'];
        }

        return $languageId;
    }

    private function prefetchRelatedFiles(array $mediaRecords): void
    {
        $mediaUids = array_values(array_unique(array_map(
            static fn(array $row): int => (int)($row['uid'] ?? 0),
            $mediaRecords
        )));
        $mediaUids = array_values(array_filter($mediaUids, static fn(int $uid): bool => $uid > 0));

        if ($mediaUids === []) {
            $this->fileReferencesByMediaUid = [];
            $this->describedSourceByFileReferenceUid = [];
            return;
        }

        $this->fileReferencesByMediaUid = $this->prefetchFileReferencesForMediaUids(
            $mediaUids,
            ['media_file', 'poster', 'captions', 'chapters', 'audio_description', 'sign_language']
        );
        $this->describedSourceByFileReferenceUid = $this->prefetchDescribedSourceFiles(
            $this->collectFileReferenceUidsForDescribedSource($mediaUids)
        );
    }

    /**
     * @return array{
     *     tracks: list<array>,
     *     mediaType: ?string,
     *     hasExternalMedia: bool,
     *     hasLocalMedia: bool,
     *     externalServiceTypes: list<string>,
     *     isPlaylist: bool,
     *     isMixedPlaylist: bool
     * }
     */
    private function buildTracksResult(array $mediaRecords, string $siteDefaultLanguageCode = 'en'): array
    {
        $tracks = [];
        $mediaType = null;
        $externalServiceTypes = [];
        $hasLocalMedia = false;
        $hasExternalMedia = false;

        foreach ($mediaRecords as $mediaRecord) {
            $track = $this->processMediaRecord($mediaRecord, $siteDefaultLanguageCode);
            if ($track === null) {
                continue;
            }
            $tracks[] = $track;

            if ($mediaType === null) {
                $mediaType = (($mediaRecord['media_type'] ?? '') === MediaType::Audio->value) ? 'audio' : 'video';
            }

            $recordType = MediaType::tryFrom((string)($mediaRecord['media_type'] ?? ''));
            if ($recordType !== null && $recordType->isExternal()) {
                $hasExternalMedia = true;
                if (!in_array($recordType->value, $externalServiceTypes, true)) {
                    $externalServiceTypes[] = $recordType->value;
                }
            } else {
                $hasLocalMedia = true;
            }
        }

        $isPlaylist = count($tracks) > 1;
        $isMixedPlaylist = $isPlaylist && $hasExternalMedia && $hasLocalMedia;

        return [
            'tracks' => $tracks,
            'mediaType' => $mediaType,
            'hasExternalMedia' => $hasExternalMedia,
            'hasLocalMedia' => $hasLocalMedia,
            'externalServiceTypes' => $externalServiceTypes,
            'isPlaylist' => $isPlaylist,
            'isMixedPlaylist' => $isMixedPlaylist,
        ];
    }

    /**
     * Apply options that depend on the built tracks (transcript, per-media UI overrides).
     */
    private function applyTrackDependentOptions(array &$playerOptions, array $trackResult, array $mediaRecords): void
    {
        $playerOptions['transcript'] = false;
        foreach ($trackResult['tracks'] as $t) {
            if (!empty($t['enableTranscript'])) {
                $playerOptions['transcript'] = true;
                break;
            }
        }
        $playerOptions['transcriptButton'] = $playerOptions['transcript'];

        if (!$trackResult['isPlaylist'] && isset($mediaRecords[0]) && !empty($mediaRecords[0]['hide_speed_button'])) {
            $playerOptions['speedButton'] = false;
        }

        // MSE-based streams (DASH via dash.js, HLS via hls.js) handle
        // "no preload before play" entirely inside their renderers and do
        // not need the player-level `deferLoad` flag:
        //   - HLS: always calls loadSource() at init (manifest only) and
        //     defers segment startLoad() until play(); pause() calls
        //     stopLoad() to halt further fragment fetches.
        //   - DASH: always calls attachSource() at init, but configures
        //     dash.js with `streaming.scheduling.scheduleWhilePaused: false`
        //     so only the MPD is fetched until the user clicks play.
        // Both keep the seekbar/duration usable before play. Accessibility
        // toggles for MSE streams don't require an active playback session
        // (they switch alternate tracks at the manifest level), so we lift
        // that gating here as well.
        if ($this->hasMseStream($trackResult['tracks'])) {
            $playerOptions['requirePlaybackForAccessibilityToggles'] = false;
        }

        // Floating player is an opt-in replacement for native Picture-in-Picture.
        // It is only meaningful for single-video records (playlists are out of scope
        // for v1) and requires the player to render <video>, so we skip audio.
        if (
            !$trackResult['isPlaylist']
            && isset($mediaRecords[0])
            && !empty($mediaRecords[0]['enable_floating_player'])
            && ($mediaRecords[0]['media_type'] ?? '') === 'video'
        ) {
            $playerOptions['floating'] = true;
            $playerOptions['floatingPosition'] = 'bottom-right';
            // The FloatingPlayerManager wires itself to the existing PiP button
            // when floating is enabled, so make sure the button is rendered.
            $playerOptions['pipButton'] = true;
        }
    }

    /**
     * Detect whether any track (or one of its alternative sources) uses a
     * MediaSource Extensions based streaming protocol (DASH or HLS).
     *
     * @param list<array> $tracks
     */
    private function hasMseStream(array $tracks): bool
    {
        $mseTypes = [
            'application/dash+xml',
            'application/x-mpegurl',
            'application/vnd.apple.mpegurl',
        ];

        foreach ($tracks as $track) {
            $candidates = [(string)($track['type'] ?? '')];
            foreach ($track['sources'] ?? [] as $source) {
                $candidates[] = (string)($source['type'] ?? '');
            }
            foreach ($candidates as $type) {
                if ($type !== '' && in_array(strtolower($type), $mseTypes, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{playlistData: ?array, optionOverrides: array}
     */
    private function buildPlaylistData(array $trackResult, array $playerOptions): array
    {
        if (!$trackResult['isPlaylist']) {
            return ['playlistData' => null, 'optionOverrides' => []];
        }

        $tracks = $trackResult['tracks'];
        $playlistData = [
            'tracks' => $tracks,
            'options' => [
                'autoplay' => $playerOptions['autoplay'],
                'autoAdvance' => $playerOptions['autoAdvance'],
                'loop' => $playerOptions['loop'],
                'showPanel' => true,
                'isMixedPlaylist' => $trackResult['isMixedPlaylist'],
                'hasExternalMedia' => $trackResult['hasExternalMedia'],
                'externalServiceTypes' => $trackResult['externalServiceTypes'],
            ],
        ];

        $optionOverrides = [];
        foreach ($tracks as $track) {
            if (!empty($track['signLanguageSrc'])) {
                $optionOverrides['signLanguageButton'] = true;
                $displayMode = $track['signLanguageDisplayMode'] ?? 'pip';
                $optionOverrides['signLanguageDisplayMode'] = in_array($displayMode, ['pip', 'main', 'both'], true) ? $displayMode : 'pip';
                break;
            }
        }

        return ['playlistData' => $playlistData, 'optionOverrides' => $optionOverrides];
    }

    /**
     * For single-item mode, extract template-level variables from the first track.
     *
     * @return array{
     *     trackData: array{
     *         videoUrl: string,
     *         poster: ?string,
     *         mediaFiles: list<array>,
     *         sources: ?list<array>,
     *         captions: list<array>,
     *         chapters: list<array>,
     *         audioDescriptionTracks: list<array>,
     *         signLanguage: list<array>
     *     },
     *     optionOverrides: array
     * }
     */
    private function extractSingleTrackData(array $trackResult, string $siteDefaultLanguageCode = 'en'): array
    {
        $empty = [
            'videoUrl' => '',
            'poster' => null,
            'mediaFiles' => [],
            'sources' => null,
            'captions' => [],
            'chapters' => [],
            'audioDescriptionTracks' => [],
            'signLanguage' => [],
        ];

        if ($trackResult['isPlaylist'] || $trackResult['tracks'] === []) {
            return ['trackData' => $empty, 'optionOverrides' => []];
        }

        $firstTrack = $trackResult['tracks'][0];
        $result = $empty;
        $optionOverrides = [];

        $firstType = MediaType::tryFrom($firstTrack['type'] ?? '');
        $firstMimeType = strtolower((string)($firstTrack['type'] ?? ''));
        $mseMimeTypes = [
            'application/dash+xml',
            'application/x-mpegurl',
            'application/vnd.apple.mpegurl',
        ];
        $hasMultipleSources = !empty($firstTrack['sources']) && count($firstTrack['sources']) > 1;
        $isSingleMseSource = !$hasMultipleSources && in_array($firstMimeType, $mseMimeTypes, true);

        $mseSourceUrl = null;
        if (!empty($firstTrack['sources'])) {
            foreach ($firstTrack['sources'] as $source) {
                $sourceMime = strtolower((string)($source['type'] ?? ''));
                if (in_array($sourceMime, $mseMimeTypes, true)) {
                    $mseSourceUrl = $source['src'] ?? null;
                    break;
                }
            }
        }

        if ($firstType !== null && in_array($firstType, [MediaType::YouTube, MediaType::Vimeo, MediaType::SoundCloud], true)) {
            $result['videoUrl'] = $firstTrack['src'];
        } elseif ($isSingleMseSource) {
            // Firefox fires a "may not load data from blob:" security warning when a
            // <video> element first tries to preload an unplayable <source> (a DASH
            // or HLS manifest) and is then handed a blob: URL by dash.js / hls.js via
            // MediaSource Extensions. Routing the manifest through data-vidply-src
            // (instead of a <source> child) avoids rendering the unplayable source in
            // the first place — the MSE renderers pick the URL up from the attribute.
            // See Mozilla bug 1768052 and moonfire-nvr issue #286 for the upstream
            // Firefox behaviour.
            $result['videoUrl'] = $firstTrack['src'];
        } elseif ($mseSourceUrl !== null) {
            // Multi-source track that contains a DASH/HLS manifest alongside native
            // fallbacks (e.g. an MP4). dash.js / hls.js take over playback and strip
            // all <source> children on init, so rendering the native fallbacks is
            // pointless and triggers the same Firefox preload/blob race described
            // above. Hand the manifest URL to the MSE renderer via data-vidply-src
            // and suppress the <source> list entirely.
            $result['videoUrl'] = $mseSourceUrl;
        } elseif ($hasMultipleSources) {
            $result['sources'] = $firstTrack['sources'];
        } else {
            $result['mediaFiles'][] = [
                'publicUrl' => $firstTrack['src'],
                'mimeType' => $firstTrack['type'],
                'label' => 'Default',
                'properties' => [],
            ];
        }

        if (!empty($firstTrack['poster'])) {
            $safePoster = $this->sanitizeUrlForCssUrl((string)$firstTrack['poster']);
            if ($safePoster !== null) {
                $result['poster'] = $safePoster;
                $optionOverrides['poster'] = $safePoster;
            }
        }

        if (!empty($firstTrack['duration'])) {
            $optionOverrides['initialDuration'] = (int)$firstTrack['duration'];
        }

        if (!empty($firstTrack['tracks'])) {
            foreach ($firstTrack['tracks'] as $textTrack) {
                if ($textTrack['kind'] === 'chapters') {
                    $result['chapters'][] = $textTrack;
                } else {
                    $result['captions'][] = $textTrack;
                }
            }
        }

        if (!empty($firstTrack['audioDescriptionSrc'])) {
            $result['audioDescriptionTracks'][] = [
                'src' => $firstTrack['audioDescriptionSrc'],
                'lang' => $siteDefaultLanguageCode,
                'label' => 'Audio Description',
                'mimeType' => '',
            ];
            $optionOverrides['audioDescriptionSrc'] = $firstTrack['audioDescriptionSrc'];
            $optionOverrides['audioDescriptionButton'] = true;
        }

        if (!empty($firstTrack['signLanguageSrc'])) {
            $result['signLanguage'][] = [
                'src' => $firstTrack['signLanguageSrc'],
                'lang' => $siteDefaultLanguageCode,
                'label' => 'Sign Language',
            ];
            $optionOverrides['signLanguageSrc'] = $firstTrack['signLanguageSrc'];
            $optionOverrides['signLanguageButton'] = true;
            $optionOverrides['signLanguagePosition'] = 'bottom-right';
            $displayMode = $firstTrack['signLanguageDisplayMode'] ?? 'pip';
            $optionOverrides['signLanguageDisplayMode'] = in_array($displayMode, ['pip', 'main', 'both'], true) ? $displayMode : 'pip';
        }

        if (!empty($firstTrack['allowDownload'])) {
            $downloadUrl = $this->resolveDownloadUrl($firstTrack);
            if ($downloadUrl !== null) {
                $result['downloadUrl'] = $downloadUrl;
                $optionOverrides['downloadButton'] = true;
            }
        }

        return ['trackData' => $result, 'optionOverrides' => $optionOverrides];
    }

    private function resolveServiceType(array $trackResult): ?string
    {
        if ($trackResult['isPlaylist'] || $trackResult['tracks'] === []) {
            return null;
        }

        $firstTrackType = $trackResult['tracks'][0]['type'] ?? null;
        $mediaType = $firstTrackType !== null ? MediaType::tryFrom($firstTrackType) : null;

        return ($mediaType !== null && $mediaType->isExternal()) ? $mediaType->value : null;
    }

    private function loadPrivacySettings(?string $serviceType, array $trackResult, int $languageId, ServerRequestInterface $request): array
    {
        $privacySettings = [];

        if ($serviceType !== null) {
            $privacySettings[$serviceType] = $this->privacySettingsService->getSettingsForService($serviceType, $languageId, $request);
        } elseif ($trackResult['isPlaylist'] && $trackResult['hasExternalMedia']) {
            foreach ($trackResult['externalServiceTypes'] as $extService) {
                $privacySettings[$extService] = $this->privacySettingsService->getSettingsForService($extService, $languageId, $request);
            }
        }

        return $privacySettings;
    }

    /**
     * Resolve site-wide play button UI and theme from extension configuration.
     *
     * @return array{
     *     playIconUrl: ?string,
     *     playIconInlineSvg: ?string,
     *     playButtonPosition: string,
     *     useCssIcons: bool,
     *     theme: string,
     *     themeSyncEnabled: bool
     * }
     */
    private function resolveExtensionConfig(): array
    {
        $playIconUrl = null;
        $playIconInlineSvg = null;
        $playButtonPosition = 'center';

        try {
            $extConf = $this->extensionConfiguration->get('mpc_vidply');
        } catch (\Throwable) {
            $extConf = [];
        }

        if (is_array($extConf)) {
            $configuredIcon = trim((string)($extConf['playIcon'] ?? ''));
            if ($configuredIcon !== '') {
                if (str_starts_with($configuredIcon, 'EXT:')) {
                    $normalizedIcon = preg_replace_callback(
                        '/^EXT:([a-z0-9_-]+)\\//i',
                        static function (array $m): string {
                            return 'EXT:' . str_replace('-', '_', $m[1]) . '/';
                        },
                        $configuredIcon
                    ) ?: $configuredIcon;

                    $webPath = $this->resolvePublicResourceWebPath($normalizedIcon);

                    if ($webPath !== '' && $webPath !== '/') {
                        $playIconUrl = $webPath;
                    } else {
                        $abs = GeneralUtility::getFileAbsFileName($normalizedIcon);
                        if ($abs !== '' && file_exists($abs)) {
                            $playIconUrl = PathUtility::getAbsoluteWebPath($abs);
                        }
                    }

                    if ($playIconUrl !== null) {
                        $playIconInlineSvg = $this->getInlineSvgFromExtPath($normalizedIcon);
                    }
                } else {
                    $playIconUrl = $this->validatePlayIconExternalUrl($configuredIcon, $extConf);
                }
            }

            if ($playIconUrl !== null) {
                $safePlayIconUrl = $this->sanitizeUrlForCssUrl($playIconUrl);
                if ($safePlayIconUrl === null) {
                    $playIconUrl = null;
                    $playIconInlineSvg = null;
                } else {
                    $playIconUrl = $safePlayIconUrl;
                }
            }

            $configuredPosition = strtolower(str_replace(' ', '-', trim((string)($extConf['playPosition'] ?? ''))));
            $allowedPositions = ['center', 'left-top', 'right-top', 'left-bottom', 'right-bottom'];
            if (in_array($configuredPosition, $allowedPositions, true)) {
                $playButtonPosition = $configuredPosition;
            }
        }

        $useCssIcons = !empty($extConf['useCssIcons']);
        $configuredTheme = strtolower(trim((string)($extConf['theme'] ?? '')));
        $theme = in_array($configuredTheme, ['dark', 'light'], true) ? $configuredTheme : 'dark';
        $themeSyncEnabled = !empty($extConf['themeSyncEnabled']);

        return [
            'playIconUrl' => $playIconUrl,
            'playIconInlineSvg' => $playIconInlineSvg,
            'playButtonPosition' => $playButtonPosition,
            'useCssIcons' => $useCssIcons,
            'theme' => $theme,
            'themeSyncEnabled' => $themeSyncEnabled,
        ];
    }

    /**
     * @return array{needsPrivacyLayer: bool, needsVidPlay: bool, needsPlaylist: bool, needsHLS: bool, needsDASH: bool}
     */
    private function resolveAssetFlags(?string $serviceType, array $trackResult): array
    {
        $isPlaylist = $trackResult['isPlaylist'];
        $needsPrivacyLayer = $serviceType !== null || ($isPlaylist && $trackResult['hasExternalMedia']);
        $needsVidPlay = $isPlaylist || $serviceType === null;
        $needsPlaylist = $isPlaylist || $needsVidPlay;

        $needsHLS = false;
        $needsDASH = false;
        foreach ($trackResult['tracks'] as $track) {
            $typesToCheck = [(string)($track['type'] ?? '')];
            foreach ($track['sources'] ?? [] as $source) {
                $typesToCheck[] = (string)($source['type'] ?? '');
            }
            foreach ($typesToCheck as $t) {
                if (in_array($t, ['application/x-mpegurl', 'application/vnd.apple.mpegurl'], true)) {
                    $needsHLS = true;
                }
                if ($t === 'application/dash+xml') {
                    $needsDASH = true;
                }
            }
            if ($needsHLS && $needsDASH) {
                break;
            }
        }

        return [
            'needsPrivacyLayer' => $needsPrivacyLayer,
            'needsVidPlay' => $needsVidPlay,
            'needsPlaylist' => $needsPlaylist,
            'needsHLS' => $needsHLS,
            'needsDASH' => $needsDASH,
        ];
    }

    /**
     * Derive effective media type for template rendering (<audio> vs <video>).
     * Handles stream types (e.g. audio HLS) that should not render a <video> element.
     */
    private function resolveEffectiveMediaType(array $trackResult): string
    {
        $resolvedMediaType = $trackResult['mediaType'] ?? 'video';
        $tracks = $trackResult['tracks'];

        if ($tracks === []) {
            return $resolvedMediaType;
        }

        $hasVideoTrack = false;
        $hasAudioTrack = false;

        foreach ($tracks as $track) {
            $type = (string)($track['type'] ?? '');
            if ($type === '') {
                continue;
            }

            $kind = (string)($track['kind'] ?? '');
            if ($kind === 'video') {
                $hasVideoTrack = true;
                continue;
            }
            if ($kind === 'audio') {
                $hasAudioTrack = true;
                continue;
            }

            $mediaType = MediaType::tryFrom($type);
            if ($mediaType !== null) {
                $mediaType->isAudioOnly() ? ($hasAudioTrack = true) : ($hasVideoTrack = true);
            } elseif (str_starts_with($type, 'video/')) {
                $hasVideoTrack = true;
            } elseif (str_starts_with($type, 'audio/')) {
                $hasAudioTrack = true;
            }
        }

        if ($hasAudioTrack && !$hasVideoTrack) {
            $resolvedMediaType = 'audio';
        }

        return $resolvedMediaType;
    }

    private function determineRenderMode(?string $serviceType, array $trackResult, string $resolvedMediaType): RenderMode
    {
        if ($trackResult['tracks'] === []) {
            return RenderMode::Video;
        }

        if ($serviceType !== null && !$trackResult['isPlaylist']) {
            return RenderMode::Privacy;
        }

        if ($trackResult['isPlaylist'] && $trackResult['hasExternalMedia']) {
            return RenderMode::MixedPlaylist;
        }

        return $resolvedMediaType === 'audio' ? RenderMode::Audio : RenderMode::Video;
    }

    private function assembleTemplateData(
        array $data,
        array $playerOptions,
        array $trackResult,
        array $singleTrackData,
        ?array $playlistData,
        ?string $serviceType,
        array $privacySettings,
        array $uiConfig,
        string $resolvedMediaType,
        RenderMode $renderMode,
        array $assetFlags
    ): array {
        $audioDescriptionTracks = $singleTrackData['audioDescriptionTracks'];
        $signLanguage = $singleTrackData['signLanguage'];

        $vidplyData = [
            'renderMode' => $renderMode->value,
            'mediaType' => $resolvedMediaType,
            'serviceType' => $serviceType,
            'isMixedPlaylist' => $trackResult['isMixedPlaylist'],
            'hasExternalMedia' => $trackResult['hasExternalMedia'],
            'externalServiceTypes' => $trackResult['externalServiceTypes'],
            'needsPrivacyLayer' => $assetFlags['needsPrivacyLayer'],
            'needsVidPlay' => $assetFlags['needsVidPlay'],
            'needsPlaylist' => $assetFlags['needsPlaylist'],
            'needsHLS' => $assetFlags['needsHLS'],
            'needsDASH' => $assetFlags['needsDASH'],
            'videoUrl' => $singleTrackData['videoUrl'],
            'poster' => $singleTrackData['poster'],
            'captions' => $singleTrackData['captions'],
            'chapters' => $singleTrackData['chapters'],
            'audioDescriptionTracks' => $audioDescriptionTracks,
            'audioDescriptionTracksJson' => $audioDescriptionTracks !== [] ? $this->safeJsonEncode($audioDescriptionTracks) : null,
            'audioDescription' => $audioDescriptionTracks[0]['src'] ?? null,
            'audioDescriptionJson' => isset($audioDescriptionTracks[0]['src']) ? $this->safeJsonEncode(['src' => $audioDescriptionTracks[0]['src']]) : null,
            'audioDescriptionDefaultSrc' => $audioDescriptionTracks[0]['src'] ?? null,
            'signLanguage' => $signLanguage,
            'signLanguageJson' => $signLanguage !== [] ? $this->safeJsonEncode($signLanguage) : null,
            'signLanguageDefaultSrc' => $signLanguage[0]['src'] ?? null,
            'signLanguageHasMultiple' => count($signLanguage) > 1,
            'signLanguageAttributes' => [],
            'signLanguagePosition' => 'bottom-right',
            'options' => $playerOptions,
            'languageSelection' => $playerOptions['language'] ?? '',
            'uniqueId' => 'vidply-' . $data['uid'],
            'playlistData' => $playlistData,
            'tracks' => $trackResult['tracks'],
            'privacySettings' => $privacySettings,
            'privacyPlayIconUrl' => $uiConfig['playIconUrl'],
            'privacyPlayIconInlineSvg' => $uiConfig['playIconInlineSvg'],
            'privacyPlayButtonPosition' => $uiConfig['playButtonPosition'],
            'useCssIcons' => $uiConfig['useCssIcons'],
            'theme' => $uiConfig['theme'],
            'themeSyncEnabled' => $uiConfig['themeSyncEnabled'],
        ];

        if ($singleTrackData['sources'] !== null) {
            $vidplyData['sources'] = $singleTrackData['sources'];
            $vidplyData['mediaFiles'] = [];
        } else {
            $vidplyData['mediaFiles'] = $singleTrackData['mediaFiles'];
        }

        if (!empty($singleTrackData['downloadUrl'])) {
            $vidplyData['downloadUrl'] = $singleTrackData['downloadUrl'];
        }

        return $vidplyData;
    }

    // -----------------------------------------------------------------------
    // URL / text sanitization
    // -----------------------------------------------------------------------

    /**
     * Allow-listed kind values for <track> elements.
     * Used to reject database values that would confuse the player or AT.
     */
    private const ALLOWED_TRACK_KINDS = ['captions', 'subtitles', 'descriptions', 'chapters', 'metadata'];

    /**
     * Return the URL unchanged if it is safe to embed inside a CSS `url('...')`
     * literal, otherwise return null.
     *
     * Rejects:
     * - empty strings
     * - control characters (incl. CR/LF/TAB)
     * - characters that can break out of the `url()` / attribute context:
     *   '"', "'", '(', ')', '\\', '<', '>', '`'
     * - non-http(s) schemes (a relative/root-relative path is accepted)
     */
    private function sanitizeUrlForCssUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (preg_match('/[\x00-\x1f\x7f"\'()\\\\<>`]/', $url)) {
            return null;
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $url)) {
            $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
            if ($scheme !== 'http' && $scheme !== 'https') {
                return null;
            }
        }

        return $url;
    }

    /**
     * Validate that a configured external play-icon URL matches the admin-configured
     * allow-list. If no allow-list is configured, external URLs are rejected — admins
     * can still reference FAL/EXT: icons safely.
     *
     * @param array<string, mixed> $extConf
     */
    private function validatePlayIconExternalUrl(string $configuredIcon, array $extConf): ?string
    {
        $parsed = parse_url($configuredIcon);
        $scheme = strtolower((string)($parsed['scheme'] ?? ''));
        $host = strtolower((string)($parsed['host'] ?? ''));

        if ($scheme === '' && $host === '') {
            return $configuredIcon;
        }

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $raw = (string)($extConf['allowedPlayIconDomains'] ?? '');
        $items = preg_split('/[,\r\n]+/', $raw) ?: [];
        $allowedPatterns = array_values(array_filter(array_map('trim', $items), static fn(string $v): bool => $v !== ''));
        if ($allowedPatterns === []) {
            return null;
        }

        foreach ($allowedPatterns as $pattern) {
            $pattern = strtolower($pattern);
            if ($pattern === $host) {
                return $configuredIcon;
            }
            if (str_starts_with($pattern, '*.')) {
                $base = substr($pattern, 2);
                if ($base === '' || !str_contains($base, '.')) {
                    continue;
                }
                if ($host === $base || str_ends_with($host, '.' . $base)) {
                    return $configuredIcon;
                }
            }
        }

        return null;
    }

    /**
     * Resolve the site's default two-letter language code for use as a fallback
     * for <track srclang> and audio-description/sign-language `lang` fields.
     */
    private function resolveSiteDefaultLanguageCode(ServerRequestInterface $request): string
    {
        $site = $request->getAttribute('site');
        if ($site !== null && method_exists($site, 'getDefaultLanguage')) {
            try {
                $defaultLanguage = $site->getDefaultLanguage();
                $code = strtolower((string)$defaultLanguage->getTwoLetterIsoCode());
                if ($code !== '') {
                    return $code;
                }
                $locale = (string)$defaultLanguage->getLocale();
                if ($locale !== '') {
                    return strtolower(explode('_', explode('-', $locale)[0])[0]);
                }
            } catch (\Throwable) {
            }
        }
        return 'en';
    }

    /**
     * @param array<string, mixed> $textTrack
     * @return array<string, mixed>
     */
    private function sanitizeTextTrack(array $textTrack, string $fallbackLanguageCode): array
    {
        $kind = strtolower((string)($textTrack['kind'] ?? ''));
        if (!in_array($kind, self::ALLOWED_TRACK_KINDS, true)) {
            $kind = 'captions';
        }
        $textTrack['kind'] = $kind;

        $srclang = trim((string)($textTrack['srclang'] ?? ''));
        if ($srclang === '') {
            $srclang = $fallbackLanguageCode;
        }
        $textTrack['srclang'] = $srclang;

        $label = $this->stripControlChars((string)($textTrack['label'] ?? ''));
        if ($label === '') {
            $label = $kind === 'chapters' ? 'Chapters' : ($kind === 'descriptions' ? 'Descriptions' : 'Captions');
        }
        if (mb_strlen($label) > 255) {
            $label = mb_substr($label, 0, 255);
        }
        $textTrack['label'] = $label;

        return $textTrack;
    }

    private function stripControlChars(string $value): string
    {
        return (string)preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $value);
    }

    // -----------------------------------------------------------------------
    // SVG handling
    // -----------------------------------------------------------------------

    private function getInlineSvgFromExtPath(string $extPathOrUrl): ?string
    {
        $value = trim($extPathOrUrl);
        if ($value === '' || !str_starts_with($value, 'EXT:')) {
            return null;
        }
        if (strtolower((string)pathinfo($value, PATHINFO_EXTENSION)) !== 'svg') {
            return null;
        }

        $normalized = preg_replace_callback(
            '/^EXT:([a-z0-9-]+)\\//i',
            static function (array $m): string {
                return 'EXT:' . str_replace('-', '_', $m[1]) . '/';
            },
            $value
        ) ?: $value;

        $abs = GeneralUtility::getFileAbsFileName($normalized);
        if (!$abs) {
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

        $loaded = @$dom->loadXML($raw, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        if (!$loaded) {
            return null;
        }

        $svg = $dom->getElementsByTagName('svg')->item(0);
        if (!$svg instanceof \DOMElement) {
            return null;
        }

        foreach (['script', 'foreignObject', 'style', 'animate', 'set', 'animateTransform', 'animateMotion'] as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                if ($node && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $this->restrictExternalReferences($dom);

        $xpath = new \DOMXPath($dom);

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

                if ($attr->localName === 'href') {
                    $val = trim((string)$attr->value);
                    if (preg_match('/^(javascript|data):/i', $val)) {
                        $attr->ownerElement?->removeAttributeNode($attr);
                    }
                }
            }
        }

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
     * Strip <image> elements and restrict <use> href to local fragment references (#id)
     * to prevent SSRF via external resource loading in inline SVGs.
     */
    private function restrictExternalReferences(\DOMDocument $dom): void
    {
        $imageNodes = $dom->getElementsByTagName('image');
        for ($i = $imageNodes->length - 1; $i >= 0; $i--) {
            $node = $imageNodes->item($i);
            if ($node && $node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }

        $useNodes = $dom->getElementsByTagName('use');
        for ($i = $useNodes->length - 1; $i >= 0; $i--) {
            $node = $useNodes->item($i);
            if (!$node instanceof \DOMElement || !$node->parentNode) {
                continue;
            }
            $href = $node->getAttribute('href') ?: $node->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
            if ($href !== '' && !str_starts_with(trim($href), '#')) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Single media record → track array
    // -----------------------------------------------------------------------

    protected function processMediaRecord(array $mediaRecord, string $siteDefaultLanguageCode = 'en'): ?array
    {
        $mediaType = MediaType::tryFrom((string)($mediaRecord['media_type'] ?? ''));
        if ($mediaType === null) {
            return null;
        }

        $mediaUid = (int)$mediaRecord['uid'];
        $track = $this->buildBaseTrackData($mediaRecord);

        $sourceData = match ($mediaType) {
            MediaType::YouTube, MediaType::Vimeo => $this->resolveEmbedSource($mediaUid, $mediaType),
            MediaType::SoundCloud => $this->resolveSoundCloudSource($mediaUid),
            MediaType::Video, MediaType::Audio => $this->resolveLocalMediaSource($mediaUid, $mediaType),
        };

        if ($sourceData === null) {
            return null;
        }

        $track = array_merge($track, $sourceData);
        $this->enrichTrackWithAccessibilityData($track, $mediaUid, $mediaRecord, $siteDefaultLanguageCode);

        return $track;
    }

    private function buildBaseTrackData(array $mediaRecord): array
    {
        $track = [
            'title' => $mediaRecord['title'] ?: 'Untitled',
        ];

        if (!empty($mediaRecord['hide_speed_button'])) {
            $track['hideSpeedButton'] = true;
        }
        if (!empty($mediaRecord['allow_download'])) {
            $track['allowDownload'] = true;
        }
        if (!empty($mediaRecord['artist'])) {
            $track['artist'] = $mediaRecord['artist'];
        }
        if (!empty($mediaRecord['duration'])) {
            $track['duration'] = (int)$mediaRecord['duration'];
        }
        if (!empty($mediaRecord['audio_description_duration'])) {
            $track['audioDescriptionDuration'] = (int)$mediaRecord['audio_description_duration'];
        }
        if (!empty($mediaRecord['description'])) {
            $track['description'] = $mediaRecord['description'];
        }

        return $track;
    }

    private function resolveDownloadUrl(array $track): ?string
    {
        $progressiveTypes = ['video/mp4', 'video/webm', 'audio/mpeg', 'audio/ogg'];

        // Prefer a progressive source from multi-source tracks
        if (!empty($track['sources'])) {
            foreach ($track['sources'] as $source) {
                if (in_array($source['type'] ?? '', $progressiveTypes, true)) {
                    return $source['src'];
                }
            }
        }

        $src = $track['src'] ?? '';
        return $src !== '' ? $src : null;
    }

    /** @return array{src: string, type: string, kind: string}|null */
    private function resolveEmbedSource(int $mediaUid, MediaType $mediaType): ?array
    {
        $mediaFiles = $this->getFileReferencesForMedia($mediaUid, 'media_file');
        if (empty($mediaFiles)) {
            return null;
        }
        $src = $this->getPublicUrlCached($mediaFiles[0]);
        if ($src === '') {
            return null;
        }
        return [
            'src' => $src,
            'type' => $mediaType->value,
            'kind' => 'video',
        ];
    }

    /** @return array{src: string, type: string, kind: string}|null */
    private function resolveSoundCloudSource(int $mediaUid): ?array
    {
        $mediaFiles = $this->getFileReferencesForMedia($mediaUid, 'media_file');
        if (empty($mediaFiles)) {
            return null;
        }
        return [
            'src' => $this->getPublicUrlCached($mediaFiles[0]),
            'type' => MediaType::SoundCloud->value,
            'kind' => 'audio',
        ];
    }

    /** @return array{src: string, type: string, kind: string, sources?: list<array>}|null */
    private function resolveLocalMediaSource(int $mediaUid, MediaType $mediaType): ?array
    {
        $mediaFiles = $this->getFileReferencesForMedia($mediaUid, 'media_file');
        if (empty($mediaFiles)) {
            return null;
        }

        $result = ['kind' => $mediaType->value];

        if (count($mediaFiles) > 1) {
            $sources = [];
            foreach ($mediaFiles as $mediaFile) {
                $publicUrl = $this->getPublicUrlCached($mediaFile);
                $mimeType = $this->getMimeTypeCached($mediaFile);
                if (in_array($mediaFile->getExtension(), ['externalaudio', 'externalvideo', 'hls', 'm3u8', 'dash', 'mpd'], true)) {
                    $mimeType = $this->inferMimeTypeFromUrlCached($publicUrl, $mimeType);
                }
                $sources[] = [
                    'src' => $publicUrl,
                    'type' => $mimeType,
                    'label' => 'Default',
                ];
            }

            usort($sources, static function (array $a, array $b): int {
                $priority = static function (string $type): int {
                    return match (true) {
                        $type === 'application/dash+xml' => 0,
                        in_array($type, ['application/x-mpegurl', 'application/vnd.apple.mpegurl'], true) => 1,
                        default => 2,
                    };
                };
                return $priority($a['type']) <=> $priority($b['type']);
            });

            $result['sources'] = $sources;
            $result['src'] = $sources[0]['src'];
            $result['type'] = $sources[0]['type'];
        } else {
            $mediaFile = $mediaFiles[0];
            $result['src'] = $this->getPublicUrlCached($mediaFile);
            $result['type'] = $this->getMimeTypeCached($mediaFile);
            if (in_array($mediaFile->getExtension(), ['externalaudio', 'externalvideo', 'hls', 'm3u8', 'dash', 'mpd'], true)) {
                $result['type'] = $this->inferMimeTypeFromUrlCached((string)$result['src'], (string)$result['type']);
            }
        }

        return $result;
    }

    private function enrichTrackWithAccessibilityData(array &$track, int $mediaUid, array $mediaRecord, string $siteDefaultLanguageCode = 'en'): void
    {
        $posterFiles = $this->getFileReferencesForMedia($mediaUid, 'poster');
        if (!empty($posterFiles)) {
            $posterUrl = (string)$posterFiles[0]->getPublicUrl();
            $safePosterUrl = $this->sanitizeUrlForCssUrl($posterUrl);
            if ($safePosterUrl !== null) {
                $track['poster'] = $safePosterUrl;
            }
        }

        $textTracks = [];

        $captionFiles = $this->getFileReferencesForMedia($mediaUid, 'captions');
        foreach ($captionFiles as $captionFile) {
            $captionUrl = (string)$captionFile->getPublicUrl();
            if ($captionUrl === '') {
                continue;
            }
            $properties = $captionFile->getProperties();
            $trackData = $this->sanitizeTextTrack([
                'src' => $captionUrl,
                'kind' => (string)($properties['tx_track_kind'] ?? ''),
                'srclang' => (string)($properties['tx_lang_code'] ?? ''),
                'label' => (string)($properties['title'] ?? ''),
            ], $siteDefaultLanguageCode);

            $descSrcUrl = $this->getDescribedSourceUrl($captionFile);
            if ($descSrcUrl !== null) {
                $trackData['describedSrc'] = $descSrcUrl;
            }

            $textTracks[] = $trackData;
        }

        $chapterFiles = $this->getFileReferencesForMedia($mediaUid, 'chapters');
        foreach ($chapterFiles as $chapterFile) {
            $chapterUrl = (string)$chapterFile->getPublicUrl();
            if ($chapterUrl === '') {
                continue;
            }
            $properties = $chapterFile->getProperties();
            $trackData = $this->sanitizeTextTrack([
                'src' => $chapterUrl,
                'kind' => 'chapters',
                'srclang' => (string)($properties['tx_lang_code'] ?? ''),
                'label' => (string)($properties['title'] ?? ''),
            ], $siteDefaultLanguageCode);

            $descSrcUrl = $this->getDescribedSourceUrl($chapterFile);
            if ($descSrcUrl !== null) {
                $trackData['describedSrc'] = $descSrcUrl;
            }

            $textTracks[] = $trackData;
        }

        if (!empty($textTracks)) {
            $track['tracks'] = $textTracks;
        }

        $audioDescFiles = $this->getFileReferencesForMedia($mediaUid, 'audio_description');
        if (!empty($audioDescFiles)) {
            $audioDescUrl = (string)$audioDescFiles[0]->getPublicUrl();
            if ($audioDescUrl !== '') {
                $track['audioDescriptionSrc'] = $audioDescUrl;
            }
        }

        $signLangFiles = $this->getFileReferencesForMedia($mediaUid, 'sign_language');
        if (!empty($signLangFiles)) {
            $signLangUrl = (string)$signLangFiles[0]->getPublicUrl();
            if ($signLangUrl !== '') {
                $track['signLanguageSrc'] = $signLangUrl;
                $track['signLanguageDisplayMode'] = $mediaRecord['sign_language_display_mode'] ?? 'pip';
            }
        }

        if (!empty($mediaRecord['enable_transcript'])) {
            $track['enableTranscript'] = true;
        }
    }

    // -----------------------------------------------------------------------
    // Utility helpers
    // -----------------------------------------------------------------------

    /**
     * TYPO3 14+: System Resource API. TYPO3 13: PathUtility (deprecated in 14, removed in 15).
     */
    private function resolvePublicResourceWebPath(string $resourcePath): string
    {
        if (class_exists(\TYPO3\CMS\Core\Resource\SystemResourceFactory::class)) {
            try {
                $factory = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\SystemResourceFactory::class);
                $publisher = GeneralUtility::makeInstance(
                    \TYPO3\CMS\Core\Resource\SystemResourcePublisherInterface::class
                );
                return (string)$publisher->generateUri(
                    $factory->createPublicResource($resourcePath),
                    null
                );
            } catch (\Throwable) {
                return '';
            }
        }

        return PathUtility::getPublicResourceWebPath($resourcePath);
    }

    /**
     * JSON-encode a value safely for embedding inside `<script type="application/json">`.
     *
     * The JSON_HEX_* flags escape `<`, `>`, `&`, `'`, `"` as `\uXXXX` sequences so
     * the emitted JSON cannot prematurely close the enclosing <script> tag or break
     * out of an attribute, which is required because consumers render the result
     * with `<f:format.raw>` (a <script type="application/json"> body cannot contain
     * HTML-escape entities). Do NOT remove these flags.
     */
    private function safeJsonEncode(mixed $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
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
            'mp3' => 'audio/mpeg',
            'ogg', 'oga' => 'audio/ogg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'm3u8' => 'application/vnd.apple.mpegurl',
            'mpd' => 'application/dash+xml',
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
     * Get the public URL for the described source file of a track.
     *
     * The described source is an alternative VTT file to use when audio description mode is enabled.
     */
    protected function getDescribedSourceUrl(FileReference $trackFileReference): ?string
    {
        $fileReferenceUid = $trackFileReference->getUid();
        if ($fileReferenceUid > 0 && isset($this->describedSourceByFileReferenceUid[$fileReferenceUid])) {
            return $this->describedSourceByFileReferenceUid[$fileReferenceUid]->getPublicUrl();
        }

        return null;
    }

    /** @return FileReference[] */
    protected function getFileReferencesForMedia(int $mediaUid, string $fieldName): array
    {
        if ($mediaUid <= 0) {
            return [];
        }
        if (isset($this->fileReferencesByMediaUid[$mediaUid][$fieldName])) {
            return $this->fileReferencesByMediaUid[$mediaUid][$fieldName];
        }
        return $this->fileRepository->findByRelation('tx_mpcvidply_media', $fieldName, $mediaUid);
    }

    // -----------------------------------------------------------------------
    // Bulk prefetch helpers
    // -----------------------------------------------------------------------

    /**
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

        foreach ($mediaUids as $mediaUid) {
            $result[$mediaUid] ??= [];
            foreach ($fieldNames as $fieldName) {
                $result[$mediaUid][$fieldName] ??= [];
            }
        }

        return $result;
    }

    /**
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
     * @param int[] $sourceFileReferenceUids
     * @return array<int, FileReference>
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
            if (isset($result[$uidForeign])) {
                continue;
            }
            try {
                $result[$uidForeign] = $this->resourceFactory->getFileReferenceObject($uid);
            } catch (ResourceDoesNotExistException) {
            }
        }

        return $result;
    }
}
