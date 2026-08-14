<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Player;

use Mpc\MpcVidply\Enums\MediaMimeType;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Everything the player is configured with: the option bitmask of the content
 * element, the overrides the built tracks imply, the playlist payload and the
 * site-wide play button and theme from the extension configuration.
 *
 * @phpstan-import-type TrackResult from TrackAssembler
 * @phpstan-type UiConfig array{
 *     playIconUrl: ?string,
 *     playIconInlineSvg: ?string,
 *     playButtonPosition: string,
 *     useCssIcons: bool,
 *     theme: string,
 *     themeSyncEnabled: bool
 * }
 */
final class PlayerOptionsBuilder
{
    private const OPT_AUTOPLAY = 1;
    private const OPT_LOOP = 2;
    private const OPT_MUTED = 4;
    private const OPT_CONTROLS = 8;
    private const OPT_CAPTIONS_DEFAULT = 16;
    private const OPT_KEYBOARD = 64;
    private const OPT_AUTO_ADVANCE = 256;
    private const OPT_RESUME_PLAYBACK = 512;

    /**
     * Site setting declared in `Configuration/Sets/mpc-vidply/settings.definitions.yaml`,
     * editable per site in the backend and readable in TypoScript as
     * `{$mpcVidply.screenReaderAnnouncements}`.
     */
    private const SETTING_SCREEN_READER_ANNOUNCEMENTS = 'mpcVidply.screenReaderAnnouncements';

    /**
     * Site setting for resume playback (`{$mpcVidply.resumePlayback}`).
     * Off by default; can be enabled site-wide or per content element.
     */
    private const SETTING_RESUME_PLAYBACK = 'mpcVidply.resumePlayback';

    private const PLAY_BUTTON_POSITIONS = ['center', 'left-top', 'right-top', 'left-bottom', 'right-bottom'];

    private const AUDIO_DESCRIPTION_MODES = ['auto', 'swap', 'vtt_speech'];

    private const SIGN_LANGUAGE_DISPLAY_MODES = ['pip', 'main', 'both'];

    private readonly ExtensionConfiguration $extensionConfiguration;
    private readonly UrlSanitizer $urlSanitizer;
    private readonly InlineSvgProvider $inlineSvgProvider;

    public function __construct(
        ?ExtensionConfiguration $extensionConfiguration = null,
        ?UrlSanitizer $urlSanitizer = null,
        ?InlineSvgProvider $inlineSvgProvider = null
    ) {
        $this->extensionConfiguration = $extensionConfiguration
            ?? GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $this->urlSanitizer = $urlSanitizer ?? GeneralUtility::makeInstance(UrlSanitizer::class);
        $this->inlineSvgProvider = $inlineSvgProvider ?? GeneralUtility::makeInstance(InlineSvgProvider::class);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function build(array $data, ?ServerRequestInterface $request = null): array
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
        $playerOptions['screenReaderAnnouncements'] = $this->resolveScreenReaderAnnouncements($request);
        $playerOptions['resumePlayback'] = $this->resolveResumePlayback($request)
            || (bool)($bits & self::OPT_RESUME_PLAYBACK);

        return $playerOptions;
    }

    /**
     * Apply options that depend on the built tracks (transcript, per-media UI overrides).
     *
     * @param array<string, mixed> $playerOptions
     * @param TrackResult $trackResult
     */
    public function applyTrackDependentOptions(array &$playerOptions, array $trackResult): void
    {
        $playerOptions['transcript'] = false;
        foreach ($trackResult['tracks'] as $t) {
            if (!empty($t['enableTranscript'])) {
                $playerOptions['transcript'] = true;
                break;
            }
        }
        $playerOptions['transcriptButton'] = $playerOptions['transcript'];

        $firstRecord = $trackResult['records'][0] ?? null;

        // Playlists carry these two per track: PlaylistInit.js hides and shows the
        // rendered buttons on every track change. Switching the option off here
        // would stop them from being built at all, leaving nothing to show again.
        if (!$trackResult['isPlaylist'] && $firstRecord !== null) {
            if (!empty($firstRecord['hide_speed_button'])) {
                $playerOptions['speedButton'] = false;
            }

            if (!empty($firstRecord['hide_help_button'])) {
                $playerOptions['helpButton'] = false;
            }
        }

        // The audio-description manager, in contrast, is shared and nothing swaps
        // its mode per track — so the first playable record decides for all of them
        // instead of silently falling back to "auto".
        if ($firstRecord !== null) {
            $audioDescriptionMode = (string)($firstRecord['audio_description_mode'] ?? 'auto');
            if (in_array($audioDescriptionMode, self::AUDIO_DESCRIPTION_MODES, true)) {
                $playerOptions['audioDescriptionMode'] = $audioDescriptionMode;
            }
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
            && $firstRecord !== null
            && !empty($firstRecord['enable_floating_player'])
            && ($firstRecord['media_type'] ?? '') === 'video'
        ) {
            $playerOptions['floating'] = true;
            $playerOptions['floatingPosition'] = 'bottom-right';
            // The FloatingPlayerManager wires itself to the existing PiP button
            // when floating is enabled, so make sure the button is rendered.
            $playerOptions['pipButton'] = true;
        }
    }

    /**
     * @param TrackResult $trackResult
     * @param array<string, mixed> $playerOptions
     * @return array{playlistData: ?array<string, mixed>, optionOverrides: array<string, mixed>}
     */
    public function buildPlaylistData(array $trackResult, array $playerOptions, string $layout = 'default'): array
    {
        if (!$trackResult['isPlaylist']) {
            return ['playlistData' => null, 'optionOverrides' => []];
        }

        // The "episodes" layout renders the track list server-side, so the
        // player's own panel would repeat the same records with a second set of
        // formatters. Suppress the panel and its toggle to keep one list.
        $showPanel = $layout !== 'episodes';

        $tracks = $trackResult['tracks'];
        $playlistData = [
            'tracks' => $tracks,
            'options' => [
                'autoplay' => $playerOptions['autoplay'],
                'autoAdvance' => $playerOptions['autoAdvance'],
                'loop' => $playerOptions['loop'],
                'showPanel' => $showPanel,
                'isMixedPlaylist' => $trackResult['isMixedPlaylist'],
                'hasExternalMedia' => $trackResult['hasExternalMedia'],
                'externalServiceTypes' => $trackResult['externalServiceTypes'],
            ],
        ];

        $optionOverrides = $showPanel ? [] : ['playlistToggleButton' => false];

        // Without a server-rendered episode list, the control bar is the only
        // place a download can be offered. The player resolves the file per
        // track, so one button serves every downloadable track.
        if ($layout !== 'episodes') {
            foreach ($tracks as $track) {
                if (!empty($track['downloadUrl'])) {
                    $optionOverrides['downloadButton'] = true;
                    break;
                }
            }
        }

        foreach ($tracks as $track) {
            if (!empty($track['signLanguageSrc'])) {
                $optionOverrides['signLanguageButton'] = true;
                $displayMode = $track['signLanguageDisplayMode'] ?? 'pip';
                $optionOverrides['signLanguageDisplayMode'] = in_array($displayMode, self::SIGN_LANGUAGE_DISPLAY_MODES, true)
                    ? $displayMode
                    : 'pip';
                break;
            }
        }

        return ['playlistData' => $playlistData, 'optionOverrides' => $optionOverrides];
    }

    /**
     * Resolve site-wide play button UI and theme from extension configuration.
     *
     * @return UiConfig
     */
    public function resolveUiConfig(): array
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

                    $playIconUrl = $this->resolveIconWebPath($normalizedIcon);

                    if ($playIconUrl !== null) {
                        $playIconInlineSvg = $this->inlineSvgProvider->fromExtensionPath($normalizedIcon);
                    }
                } else {
                    $playIconUrl = $this->urlSanitizer->validateExternalIconUrl($configuredIcon, $extConf);
                }
            }

            if ($playIconUrl !== null) {
                $safePlayIconUrl = $this->urlSanitizer->sanitizeForCssUrl($playIconUrl);
                if ($safePlayIconUrl === null) {
                    $playIconUrl = null;
                    $playIconInlineSvg = null;
                } else {
                    $playIconUrl = $safePlayIconUrl;
                }
            }

            $configuredPosition = strtolower(str_replace(' ', '-', trim((string)($extConf['playPosition'] ?? ''))));
            if (in_array($configuredPosition, self::PLAY_BUTTON_POSITIONS, true)) {
                $playButtonPosition = $configuredPosition;
            }
        }

        $useCssIcons = !empty($extConf['useCssIcons']);
        $configuredTheme = strtolower(trim((string)($extConf['theme'] ?? '')));
        $theme = in_array($configuredTheme, ['dark', 'light'], true) ? $configuredTheme : 'dark';

        return [
            'playIconUrl' => $playIconUrl,
            'playIconInlineSvg' => $playIconInlineSvg,
            'playButtonPosition' => $playButtonPosition,
            'useCssIcons' => $useCssIcons,
            'theme' => $theme,
            'themeSyncEnabled' => !empty($extConf['themeSyncEnabled']),
        ];
    }

    /**
     * Status announcements stay on unless a site turns them off; a request
     * without a resolved site (backend preview, CLI) keeps the default.
     */
    private function resolveScreenReaderAnnouncements(?ServerRequestInterface $request): bool
    {
        $site = $request?->getAttribute('site');
        if (!$site instanceof Site) {
            return true;
        }

        return (bool)$site->getSettings()->get(self::SETTING_SCREEN_READER_ANNOUNCEMENTS, true);
    }

    /**
     * Resume playback stays off unless a site or content element turns it on.
     * Requests without a resolved site (backend preview, CLI) keep the default.
     */
    private function resolveResumePlayback(?ServerRequestInterface $request): bool
    {
        $site = $request?->getAttribute('site');
        if (!$site instanceof Site) {
            return false;
        }

        return (bool)$site->getSettings()->get(self::SETTING_RESUME_PLAYBACK, false);
    }

    /**
     * Detect whether any track (or one of its alternative sources) uses a
     * MediaSource Extensions based streaming protocol (DASH or HLS).
     *
     * @param list<array<string, mixed>> $tracks
     */
    private function hasMseStream(array $tracks): bool
    {
        foreach ($tracks as $track) {
            $candidates = [(string)($track['type'] ?? '')];
            foreach ($track['sources'] ?? [] as $source) {
                $candidates[] = (string)($source['type'] ?? '');
            }
            foreach ($candidates as $type) {
                if (MediaMimeType::isStreaming($type)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function resolveIconWebPath(string $normalizedIcon): ?string
    {
        $webPath = $this->resolvePublicResourceWebPath($normalizedIcon);
        if ($webPath !== '' && $webPath !== '/') {
            return $webPath;
        }

        $abs = GeneralUtility::getFileAbsFileName($normalizedIcon);

        return ($abs !== '' && file_exists($abs)) ? PathUtility::getAbsoluteWebPath($abs) : null;
    }

    /**
     * Resolve a public web path for an `EXT:`/`Resources/Public/...` resource.
     *
     * TYPO3 14+ exposes the System Resource API (`SystemResourceFactory` +
     * `SystemResourcePublisherInterface`); on those versions we always use it.
     * TYPO3 13.4 has neither, so we must fall back to
     * `PathUtility::getPublicResourceWebPath()`. That static method is marked
     * `@deprecated` in 14 (#107537) but still functional, and it's the only
     * supported way on 13.x — the `class_exists()` guard above ensures it is
     * never invoked when the new API is available, so `// @extensionScannerIgnoreLine`
     * is correct here.
     */
    private function resolvePublicResourceWebPath(string $resourcePath): string
    {
        $factoryClass = 'TYPO3\\CMS\\Core\\SystemResource\\SystemResourceFactory';
        $publisherInterface = 'TYPO3\\CMS\\Core\\SystemResource\\Publishing\\SystemResourcePublisherInterface';

        if (class_exists($factoryClass) && interface_exists($publisherInterface)) {
            try {
                $factory = GeneralUtility::makeInstance($factoryClass);
                $publisher = GeneralUtility::makeInstance($publisherInterface);
                if (method_exists($factory, 'createPublicResource') && method_exists($publisher, 'generateUri')) {
                    return (string)$publisher->generateUri(
                        $factory->createPublicResource($resourcePath),
                        null
                    );
                }
            } catch (\Throwable) {
                return '';
            }
        }

        // @extensionScannerIgnoreLine
        return PathUtility::getPublicResourceWebPath($resourcePath);
    }
}
