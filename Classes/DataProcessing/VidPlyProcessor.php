<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\DataProcessing;

use Mpc\MpcVidply\Repository\MediaRepository;
use Mpc\MpcVidply\Service\FrontendLanguageResolver;
use Mpc\MpcVidply\Service\Player\DownloadResolver;
use Mpc\MpcVidply\Service\Player\EpisodeListBuilder;
use Mpc\MpcVidply\Service\Player\LocaleFormatter;
use Mpc\MpcVidply\Service\Player\PlayerOptionsBuilder;
use Mpc\MpcVidply\Service\Player\PlayerTemplateDataBuilder;
use Mpc\MpcVidply\Service\Player\RenderDecisionResolver;
use Mpc\MpcVidply\Service\Player\SingleTrackDataExtractor;
use Mpc\MpcVidply\Service\Player\TrackAssembler;
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
 */
class VidPlyProcessor implements DataProcessorInterface
{
    private readonly MediaRepository $mediaRepository;
    private readonly PrivacySettingsService $privacySettingsService;
    private readonly TrackAssembler $trackAssembler;
    private readonly PlayerOptionsBuilder $playerOptionsBuilder;
    private readonly EpisodeListBuilder $episodeListBuilder;
    private readonly DownloadResolver $downloadResolver;
    private readonly LocaleFormatter $localeFormatter;
    private readonly SingleTrackDataExtractor $singleTrackDataExtractor;
    private readonly RenderDecisionResolver $renderDecisionResolver;
    private readonly PlayerTemplateDataBuilder $templateDataBuilder;

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
        ?LocaleFormatter $localeFormatter = null,
        ?SingleTrackDataExtractor $singleTrackDataExtractor = null,
        ?RenderDecisionResolver $renderDecisionResolver = null,
        ?PlayerTemplateDataBuilder $templateDataBuilder = null
    ) {
        $this->mediaRepository = $mediaRepository ?? GeneralUtility::makeInstance(MediaRepository::class);
        $this->privacySettingsService = $privacySettingsService
            ?? GeneralUtility::makeInstance(PrivacySettingsService::class);
        $this->trackAssembler = $trackAssembler ?? GeneralUtility::makeInstance(TrackAssembler::class);
        $this->playerOptionsBuilder = $playerOptionsBuilder
            ?? GeneralUtility::makeInstance(PlayerOptionsBuilder::class);
        $this->episodeListBuilder = $episodeListBuilder ?? GeneralUtility::makeInstance(EpisodeListBuilder::class);
        $this->downloadResolver = $downloadResolver ?? GeneralUtility::makeInstance(DownloadResolver::class);
        $this->localeFormatter = $localeFormatter ?? GeneralUtility::makeInstance(LocaleFormatter::class);
        $this->singleTrackDataExtractor = $singleTrackDataExtractor
            ?? GeneralUtility::makeInstance(SingleTrackDataExtractor::class);
        $this->renderDecisionResolver = $renderDecisionResolver
            ?? GeneralUtility::makeInstance(RenderDecisionResolver::class);
        $this->templateDataBuilder = $templateDataBuilder
            ?? GeneralUtility::makeInstance(PlayerTemplateDataBuilder::class);
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
        // MM uid_local: the default-language CE (l18n parent) wins, the translated
        // CE is only read when the parent has no relations (see MediaRepository::findByContentUid)
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

        $siteDefaultLanguageCode = $this->localeFormatter->resolveSiteDefaultLanguageCode($request);
        $layout = $this->episodeListBuilder->resolveLayout($data);
        $trackResult = $this->trackAssembler->assemble($mediaRecords, $siteDefaultLanguageCode, $locale);
        if ($layout !== 'default') {
            $trackResult = $this->withoutPlayerLongDescriptions($trackResult);
        }
        $this->playerOptionsBuilder->applyTrackDependentOptions($playerOptions, $trackResult, $data);

        $serviceType = $this->renderDecisionResolver->resolveServiceType($trackResult);
        $singleEmbedInPlayer = $this->renderDecisionResolver->isSingleEmbedInPlayer($serviceType, $trackResult, $playerOptions);

        $playlistResult = $this->playerOptionsBuilder->buildPlaylistData(
            $trackResult,
            $playerOptions,
            $layout,
            $data,
            $singleEmbedInPlayer
        );
        $playlistData = $playlistResult['playlistData'];
        $playerOptions = array_merge($playerOptions, $playlistResult['optionOverrides']);

        $singleTrackResult = $this->singleTrackDataExtractor->extract($trackResult, $siteDefaultLanguageCode);
        $singleTrackData = $singleTrackResult['trackData'];
        $playerOptions = array_merge($playerOptions, $singleTrackResult['optionOverrides']);

        $privacySettings = $this->loadPrivacySettings($serviceType, $trackResult, $languageId, $request);
        $uiConfig = $this->playerOptionsBuilder->resolveUiConfig();
        $playerOptions['theme'] = $uiConfig['theme'];

        $resolvedMediaType = $this->renderDecisionResolver->resolveEffectiveMediaType($trackResult);
        $renderMode = $this->renderDecisionResolver->determineRenderMode(
            $serviceType,
            $trackResult,
            $resolvedMediaType,
            $singleEmbedInPlayer
        );
        $assetFlags = $this->renderDecisionResolver->resolveAssetFlags($serviceType, $trackResult, $singleEmbedInPlayer);

        $episodes = $this->episodeListBuilder->build($layout, $trackResult, $languageId, $data, $locale);

        return $this->templateDataBuilder->build(
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

    /**
     * Card and episode layouts render long descriptions in their own header
     * above the player, so keep them out of the in-player track-info header.
     *
     * @param TrackResult $trackResult
     * @return TrackResult
     */
    private function withoutPlayerLongDescriptions(array $trackResult): array
    {
        foreach ($trackResult['tracks'] as &$track) {
            unset($track['longDescription']);
        }
        unset($track);

        return $trackResult;
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
}
