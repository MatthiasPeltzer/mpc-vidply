<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\DataProcessing;

use Mpc\MpcVidply\Enums\MediaMimeType;
use Mpc\MpcVidply\Enums\MediaType;
use Mpc\MpcVidply\Enums\RenderMode;
use Mpc\MpcVidply\Repository\MediaRepository;
use Mpc\MpcVidply\Service\FrontendLanguageResolver;
use Mpc\MpcVidply\Service\Player\DownloadResolver;
use Mpc\MpcVidply\Service\Player\EpisodeListBuilder;
use Mpc\MpcVidply\Service\Player\LocaleFormatter;
use Mpc\MpcVidply\Service\Player\PlayerOptionsBuilder;
use Mpc\MpcVidply\Service\Player\TrackAssembler;
use Mpc\MpcVidply\Service\Player\UrlSanitizer;
use Mpc\MpcVidply\Service\PrivacySettingsService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

/**
 * DataProcessor for VidPly Player
 *
 * Processes content element data and prepares it for the Fluid template.
 * Works with standalone media records from tx_mpcvidply_media table.
 *
 * The work itself lives in the `Service\Player` collaborators — this class only
 * decides which of them run and merges their results into the template array.
 *
 * Uses constructor injection with fallback for TYPO3 13/14 compatibility.
 * DataProcessors are instantiated via GeneralUtility::makeInstance() from TypoScript,
 * so we need optional parameters with service locator fallback.
 *
 * @phpstan-import-type TrackResult from TrackAssembler
 * @phpstan-import-type UiConfig from PlayerOptionsBuilder
 */
class VidPlyProcessor implements DataProcessorInterface
{
    private readonly MediaRepository $mediaRepository;
    private readonly PrivacySettingsService $privacySettingsService;
    private readonly TrackAssembler $trackAssembler;
    private readonly PlayerOptionsBuilder $playerOptionsBuilder;
    private readonly EpisodeListBuilder $episodeListBuilder;
    private readonly DownloadResolver $downloadResolver;
    private readonly UrlSanitizer $urlSanitizer;
    private readonly LocaleFormatter $localeFormatter;

    /**
     * Parameters are optional to support GeneralUtility::makeInstance() calls.
     * When autowired (e.g., in tests or TYPO3 14), dependencies are injected.
     */
    public function __construct(
        ?MediaRepository $mediaRepository = null,
        ?PrivacySettingsService $privacySettingsService = null,
        ?TrackAssembler $trackAssembler = null,
        ?PlayerOptionsBuilder $playerOptionsBuilder = null,
        ?EpisodeListBuilder $episodeListBuilder = null,
        ?DownloadResolver $downloadResolver = null,
        ?UrlSanitizer $urlSanitizer = null,
        ?LocaleFormatter $localeFormatter = null
    ) {
        $this->mediaRepository = $mediaRepository ?? GeneralUtility::makeInstance(MediaRepository::class);
        $this->privacySettingsService = $privacySettingsService
            ?? GeneralUtility::makeInstance(PrivacySettingsService::class);
        $this->trackAssembler = $trackAssembler ?? GeneralUtility::makeInstance(TrackAssembler::class);
        $this->playerOptionsBuilder = $playerOptionsBuilder
            ?? GeneralUtility::makeInstance(PlayerOptionsBuilder::class);
        $this->episodeListBuilder = $episodeListBuilder ?? GeneralUtility::makeInstance(EpisodeListBuilder::class);
        $this->downloadResolver = $downloadResolver ?? GeneralUtility::makeInstance(DownloadResolver::class);
        $this->urlSanitizer = $urlSanitizer ?? GeneralUtility::makeInstance(UrlSanitizer::class);
        $this->localeFormatter = $localeFormatter ?? GeneralUtility::makeInstance(LocaleFormatter::class);
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
        $locale = $this->localeFormatter->resolveLocale($request);
        $playerOptions = $this->playerOptionsBuilder->build($data, $request);
        $languageId = $languageIdOverride ?? FrontendLanguageResolver::resolveLanguageId($request, $data);

        $siteDefaultLanguageCode = $this->resolveSiteDefaultLanguageCode($request);
        $trackResult = $this->trackAssembler->assemble($mediaRecords, $siteDefaultLanguageCode, $locale);
        $this->playerOptionsBuilder->applyTrackDependentOptions($playerOptions, $trackResult);

        $layout = $this->episodeListBuilder->resolveLayout($data);

        $playlistResult = $this->playerOptionsBuilder->buildPlaylistData($trackResult, $playerOptions, $layout);
        $playlistData = $playlistResult['playlistData'];
        $playerOptions = array_merge($playerOptions, $playlistResult['optionOverrides']);

        $singleTrackResult = $this->extractSingleTrackData($trackResult, $siteDefaultLanguageCode);
        $singleTrackData = $singleTrackResult['trackData'];
        $playerOptions = array_merge($playerOptions, $singleTrackResult['optionOverrides']);

        $serviceType = $this->resolveServiceType($trackResult);
        $privacySettings = $this->loadPrivacySettings($serviceType, $trackResult, $languageId, $request);
        $uiConfig = $this->playerOptionsBuilder->resolveUiConfig();
        $playerOptions['theme'] = $uiConfig['theme'];

        $resolvedMediaType = $this->resolveEffectiveMediaType($trackResult);
        $renderMode = $this->determineRenderMode($serviceType, $trackResult, $resolvedMediaType);
        $assetFlags = $this->resolveAssetFlags($serviceType, $trackResult);

        $episodes = $this->episodeListBuilder->build($layout, $trackResult, $languageId, $data, $locale);

        return $this->assembleTemplateData(
            $data,
            $playerOptions,
            $trackResult,
            $singleTrackData,
            $playlistData,
            $serviceType,
            $privacySettings,
            $uiConfig,
            $resolvedMediaType,
            $renderMode,
            $assetFlags,
            $layout,
            $episodes
        );
    }

    /**
     * Resolve only the data needed for structured data (JSON-LD) output of a single
     * media record: the first track's source URL(s) and an optional download URL.
     *
     * This deliberately skips the full player assembly (privacy layer, playlist,
     * caption/chapter/sign-language enrichment, UI options) so gallery and list
     * pages can build a {@see \Mpc\MpcVidply\Service\MediaObjectJsonLdBuilder}
     * node per item without paying the cost of rendering a player for each one.
     *
     * @param list<array<string, mixed>> $mediaRecords
     * @return array{tracks: list<array<string, mixed>>, downloadUrl: ?string}
     */
    public function assembleStructuredDataContext(array $mediaRecords, ServerRequestInterface $request): array
    {
        $empty = ['tracks' => [], 'downloadUrl' => null];

        $firstRecord = $mediaRecords[0] ?? null;
        if (!is_array($firstRecord)) {
            return $empty;
        }

        // No locale: a JSON-LD node carries machine-readable dates, not a label
        // for the current site language.
        $track = $this->trackAssembler->assembleStructuredDataTrack($firstRecord);
        if ($track === null) {
            return $empty;
        }

        return [
            'tracks' => [$track],
            'downloadUrl' => $this->downloadResolver->resolveUrl($track),
        ];
    }

    // -----------------------------------------------------------------------
    // Decomposed phases of process()
    // -----------------------------------------------------------------------

    /**
     * For single-item mode, extract template-level variables from the first track.
     *
     * @param TrackResult $trackResult
     * @return array{trackData: array<string, mixed>, optionOverrides: array<string, mixed>}
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
        $hasMultipleSources = !empty($firstTrack['sources']) && count($firstTrack['sources']) > 1;
        $isSingleMseSource = !$hasMultipleSources && MediaMimeType::isStreaming($firstMimeType);

        $mseSourceUrl = null;
        if (!empty($firstTrack['sources'])) {
            foreach ($firstTrack['sources'] as $source) {
                $sourceMime = strtolower((string)($source['type'] ?? ''));
                if (MediaMimeType::isStreaming($sourceMime)) {
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
            $safePoster = $this->urlSanitizer->sanitizeForCssUrl((string)$firstTrack['poster']);
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

        $audioDescriptionMode = (string)($firstTrack['audioDescriptionMode'] ?? 'auto');
        if (in_array($audioDescriptionMode, ['auto', 'swap', 'vtt_speech'], true)) {
            $optionOverrides['audioDescriptionMode'] = $audioDescriptionMode;
        }

        $hasDescriptionsTrack = false;
        if (!empty($firstTrack['tracks'])) {
            foreach ($firstTrack['tracks'] as $textTrack) {
                if (($textTrack['kind'] ?? '') === 'descriptions') {
                    $hasDescriptionsTrack = true;
                    break;
                }
            }
        }
        if (!empty($firstTrack['audioDescriptionSrc']) || $hasDescriptionsTrack) {
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
            $downloadUrl = $this->downloadResolver->resolveUrl($firstTrack);
            if ($downloadUrl !== null) {
                $result['downloadUrl'] = $downloadUrl;
                $optionOverrides['downloadButton'] = true;
            }
        }

        return ['trackData' => $result, 'optionOverrides' => $optionOverrides];
    }

    /**
     * @param TrackResult $trackResult
     */
    private function resolveServiceType(array $trackResult): ?string
    {
        if ($trackResult['isPlaylist'] || $trackResult['tracks'] === []) {
            return null;
        }

        $firstTrackType = $trackResult['tracks'][0]['type'] ?? null;
        $mediaType = $firstTrackType !== null ? MediaType::tryFrom($firstTrackType) : null;

        return ($mediaType !== null && $mediaType->isExternal()) ? $mediaType->value : null;
    }

    /**
     * @param TrackResult $trackResult
     * @return array<string, array<string, string>>
     */
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
     * @param TrackResult $trackResult
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
                if (MediaMimeType::isHls($t)) {
                    $needsHLS = true;
                }
                if (MediaMimeType::isDash($t)) {
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
     *
     * @param TrackResult $trackResult
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

    /**
     * @param TrackResult $trackResult
     */
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

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $playerOptions
     * @param TrackResult $trackResult
     * @param array<string, mixed> $singleTrackData
     * @param array<string, mixed>|null $playlistData
     * @param array<string, array<string, string>> $privacySettings
     * @param UiConfig $uiConfig
     * @param array{needsPrivacyLayer: bool, needsVidPlay: bool, needsPlaylist: bool, needsHLS: bool, needsDASH: bool} $assetFlags
     * @param list<array<string, mixed>> $episodes
     * @return array<string, mixed>
     */
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
        array $assetFlags,
        string $layout = 'default',
        array $episodes = []
    ): array {
        $audioDescriptionTracks = $singleTrackData['audioDescriptionTracks'];
        $signLanguage = $singleTrackData['signLanguage'];

        $episodeSettings = $this->episodeListBuilder->resolveListSettings($data, $layout, $episodes);

        $vidplyData = [
            'layout' => $layout,
            'episodes' => $episodes,
            // The list may be sorted, so the episode the player starts on is the
            // one carrying track index 0 rather than the first array entry.
            'episode' => $this->episodeListBuilder->resolveLeadEpisode($episodes),
            'episodeSort' => $episodeSettings['episodeSort'],
            'paginationEnabled' => $episodeSettings['paginationEnabled'],
            'paginationPerPage' => $episodeSettings['paginationPerPage'],
            'paginationActive' => $episodeSettings['paginationActive'],
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

    /**
     * Resolve the site's default two-letter language code for use as a fallback
     * for <track srclang> and audio-description/sign-language `lang` fields.
     *
     * `SiteLanguage::getTwoLetterIsoCode()` was removed in TYPO3 13 (Breaking
     * #100963), so we read `Locale::getLanguageCode()` instead, which has been
     * available since TYPO3 12 and works identically on 13.4 and 14.x.
     */
    private function resolveSiteDefaultLanguageCode(ServerRequestInterface $request): string
    {
        $site = $request->getAttribute('site');
        if ($site !== null && method_exists($site, 'getDefaultLanguage')) {
            try {
                $locale = $site->getDefaultLanguage()->getLocale();
                $code = strtolower($locale->getLanguageCode());
                if ($code !== '') {
                    return $code;
                }
                $localeName = (string)$locale;
                if ($localeName !== '') {
                    return strtolower(explode('_', explode('-', $localeName)[0])[0]);
                }
            } catch (\Throwable) {
            }
        }

        return 'en';
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
}
