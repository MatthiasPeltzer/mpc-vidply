<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Player;

use Mpc\MpcVidply\Enums\RenderMode;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Merges the results of the player pipeline into the `vidply` array the Fluid
 * templates read.
 *
 * @phpstan-import-type TrackResult from TrackAssembler
 * @phpstan-import-type UiConfig from PlayerOptionsBuilder
 * @phpstan-import-type AssetFlags from RenderDecisionResolver
 */
final class PlayerTemplateDataBuilder
{
    private readonly EpisodeListBuilder $episodeListBuilder;

    public function __construct(?EpisodeListBuilder $episodeListBuilder = null)
    {
        $this->episodeListBuilder = $episodeListBuilder ?? GeneralUtility::makeInstance(EpisodeListBuilder::class);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $playerOptions
     * @param TrackResult $trackResult
     * @param array<string, mixed> $singleTrackData
     * @param array<string, mixed>|null $playlistData
     * @param array<string, array<string, string>> $privacySettings
     * @param UiConfig $uiConfig
     * @param AssetFlags $assetFlags
     * @param list<array<string, mixed>> $episodes
     * @return array<string, mixed>
     */
    public function build(
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
            'privacyHeadlineLevel' => $this->resolvePrivacyHeadlineLevel($data),
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
     * Heading level of the privacy layer headline: one level below the content
     * element's own header when it renders one, otherwise level 2. Layout 0 is
     * the site's default header (<h2>), layouts 7–12 are visually hidden
     * <h1>–<h6>, layout 100 hides the header (see ContentElement/Header.html).
     *
     * @param array<string, mixed> $data
     */
    public function resolvePrivacyHeadlineLevel(array $data): int
    {
        $headerLayout = (int)($data['header_layout'] ?? 0);
        if (trim((string)($data['header'] ?? '')) === '' || $headerLayout === 100) {
            return 2;
        }
        $headerLevel = match (true) {
            $headerLayout >= 1 && $headerLayout <= 6 => $headerLayout,
            $headerLayout >= 7 && $headerLayout <= 12 => $headerLayout - 6,
            default => 2,
        };

        return min(6, $headerLevel + 1);
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
    public function safeJsonEncode(mixed $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }
}
