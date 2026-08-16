<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service\Player;

use Mpc\MpcVidply\Service\Player\PlayerOptionsBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The option mapping is pure, so the subject is built without its constructor:
 * only {@see PlayerOptionsBuilder::resolveUiConfig()} needs the extension
 * configuration, and that is covered by the functional suite.
 */
final class PlayerOptionsBuilderTest extends TestCase
{
    private PlayerOptionsBuilder $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = (new \ReflectionClass(PlayerOptionsBuilder::class))->newInstanceWithoutConstructor();
    }

    #[Test]
    public function buildDefaultsWhenNoBitsSet(): void
    {
        $options = $this->subject->build([]);

        self::assertFalse($options['autoplay']);
        self::assertFalse($options['controls']);
        self::assertFalse($options['keyboard']);
        self::assertTrue($options['responsive']);
        self::assertSame(0.8, $options['volume']);
        self::assertSame(1.0, $options['playbackSpeed']);
        self::assertSame('', $options['language']);
        // deferLoad is the inverse of autoplay.
        self::assertTrue($options['deferLoad']);
        self::assertTrue($options['requirePlaybackForAccessibilityToggles']);
        self::assertSame('metadata', $options['preload']);
        self::assertFalse($options['resumePlayback']);
    }

    #[Test]
    public function buildTakesResumePlaybackFromTheSiteSettings(): void
    {
        $site = new Site('mpc', 1, [
            'base' => '/',
            'settings' => ['mpcVidply' => ['resumePlayback' => true]],
        ]);
        $request = (new ServerRequest())->withAttribute('site', $site);

        $options = $this->subject->build([], $request);

        self::assertTrue($options['resumePlayback']);
    }

    #[Test]
    public function buildEnablesResumePlaybackFromTheContentElementOption(): void
    {
        $options = $this->subject->build(['tx_mpcvidply_resume_playback' => 1]);

        self::assertTrue($options['resumePlayback']);
    }

    #[Test]
    public function buildDecodesBitmask(): void
    {
        // CONTROLS (8) + KEYBOARD (32) + AUTO_ADVANCE (64) = 104 (the documented default)
        $options = $this->subject->build([
            'tx_mpcvidply_options' => 104,
            'tx_mpcvidply_volume' => 0.5,
            'tx_mpcvidply_playback_speed' => 1.5,
            'tx_mpcvidply_language' => 'de',
        ]);

        self::assertTrue($options['controls']);
        self::assertTrue($options['keyboard']);
        self::assertTrue($options['autoAdvance']);
        self::assertFalse($options['autoplay']);
        self::assertFalse($options['loop']);
        self::assertFalse($options['muted']);
        self::assertFalse($options['captionsDefault']);
        self::assertSame(0.5, $options['volume']);
        self::assertSame(1.5, $options['playbackSpeed']);
        self::assertSame('de', $options['language']);
        self::assertSame('de', $options['defaultTranscriptLanguage']);
    }

    #[Test]
    public function buildDecodesKeyboardFromFormEnginePositionalBitmask(): void
    {
        // Muted + controls + captions + keyboard (FormEngine positions 2–5) = 60
        $options = $this->subject->build(['tx_mpcvidply_options' => 60]);

        self::assertTrue($options['keyboard']);
        self::assertFalse($options['autoAdvance']);
    }

    #[Test]
    public function buildKeepsScreenReaderAnnouncementsOnWithoutASite(): void
    {
        $options = $this->subject->build([]);

        self::assertTrue($options['screenReaderAnnouncements']);
    }

    #[Test]
    public function buildTakesScreenReaderAnnouncementsFromTheSiteSettings(): void
    {
        $site = new Site('mpc', 1, [
            'base' => '/',
            'settings' => ['mpcVidply' => ['screenReaderAnnouncements' => false]],
        ]);
        $request = (new ServerRequest())->withAttribute('site', $site);

        $options = $this->subject->build([], $request);

        self::assertFalse($options['screenReaderAnnouncements']);
    }

    #[Test]
    public function buildAutoplayDisablesDeferLoad(): void
    {
        // AUTOPLAY (1) + CONTROLS (8)
        $options = $this->subject->build(['tx_mpcvidply_options' => 9]);

        self::assertTrue($options['autoplay']);
        self::assertFalse($options['deferLoad']);
        self::assertFalse($options['requirePlaybackForAccessibilityToggles']);
    }

    /**
     * @return array<string, array{0: array<int, array<string, mixed>>, 1: bool}>
     */
    public static function mseStreamProvider(): array
    {
        return [
            'plain mp4 is not mse' => [[['type' => 'video/mp4']], false],
            'hls track type is mse' => [[['type' => 'application/x-mpegurl']], true],
            'apple hls track type is mse' => [[['type' => 'application/vnd.apple.mpegurl']], true],
            'dash track type is mse' => [[['type' => 'application/dash+xml']], true],
            'mse in nested source' => [[['type' => 'video/mp4', 'sources' => [['type' => 'application/dash+xml']]]], true],
            'case insensitive' => [[['type' => 'APPLICATION/DASH+XML']], true],
            'empty tracks' => [[], false],
        ];
    }

    /**
     * An MSE stream lifts the playback gate on the accessibility toggles, which
     * is the only externally visible effect of the detection.
     *
     * @param array<int, array<string, mixed>> $tracks
     */
    #[Test]
    #[DataProvider('mseStreamProvider')]
    public function applyTrackDependentOptionsDetectsStreamingProtocols(array $tracks, bool $isStream): void
    {
        $playerOptions = ['requirePlaybackForAccessibilityToggles' => true];

        $this->subject->applyTrackDependentOptions($playerOptions, [
            'isPlaylist' => false,
            'tracks' => $tracks,
            'records' => [],
        ]);

        self::assertSame(!$isStream, $playerOptions['requirePlaybackForAccessibilityToggles']);
    }

    #[Test]
    public function applyTrackDependentOptionsDisablesTrackInfoForSingleItemByDefault(): void
    {
        $playerOptions = [];

        $this->subject->applyTrackDependentOptions($playerOptions, [
            'isPlaylist' => false,
            'tracks' => [['title' => 'A']],
            'records' => [],
        ]);

        self::assertFalse($playerOptions['showTrackInfo']);
    }

    #[Test]
    public function applyTrackDependentOptionsEnablesTrackInfoForSingleItemWhenRequested(): void
    {
        $playerOptions = [];

        $this->subject->applyTrackDependentOptions($playerOptions, [
            'isPlaylist' => false,
            'tracks' => [['title' => 'A']],
            'records' => [],
        ], ['tx_mpcvidply_show_track_info' => 1]);

        self::assertTrue($playerOptions['showTrackInfo']);
    }

    #[Test]
    public function applyTrackDependentOptionsLeavesTrackInfoUnsetForPlaylists(): void
    {
        $playerOptions = [];

        $this->subject->applyTrackDependentOptions($playerOptions, [
            'isPlaylist' => true,
            'tracks' => [['title' => 'A'], ['title' => 'B']],
            'records' => [],
        ], ['tx_mpcvidply_show_track_info' => 1]);

        self::assertArrayNotHasKey('showTrackInfo', $playerOptions);
    }

    #[Test]
    public function applyTrackDependentOptionsHidesHelpButtonForSingleItem(): void
    {
        $playerOptions = ['speedButton' => true];
        $trackResult = [
            'isPlaylist' => false,
            'tracks' => [['title' => 'Test']],
            'records' => [['hide_help_button' => 1, 'media_type' => 'video']],
        ];

        $this->subject->applyTrackDependentOptions($playerOptions, $trackResult);

        self::assertFalse($playerOptions['helpButton']);
    }

    /**
     * PlaylistInit.js hides and shows both buttons per track, which only works as
     * long as the option leaves them rendered.
     */
    #[Test]
    public function applyTrackDependentOptionsLeavesPlaylistButtonsToThePerTrackHandler(): void
    {
        $playerOptions = [];
        $trackResult = [
            'isPlaylist' => true,
            'tracks' => [['title' => 'A'], ['title' => 'B']],
            'records' => [
                ['hide_help_button' => 1, 'hide_speed_button' => 1, 'media_type' => 'video'],
                ['media_type' => 'video'],
            ],
        ];

        $this->subject->applyTrackDependentOptions($playerOptions, $trackResult);

        self::assertArrayNotHasKey('helpButton', $playerOptions);
        self::assertArrayNotHasKey('speedButton', $playerOptions);
    }

    #[Test]
    public function applyTrackDependentOptionsTakesAudioDescriptionModeFromTheFirstRecord(): void
    {
        $playerOptions = [];
        $trackResult = [
            'isPlaylist' => true,
            'tracks' => [['title' => 'A'], ['title' => 'B']],
            'records' => [
                ['audio_description_mode' => 'swap', 'media_type' => 'video'],
                ['audio_description_mode' => 'vtt_speech', 'media_type' => 'video'],
            ],
        ];

        $this->subject->applyTrackDependentOptions($playerOptions, $trackResult);

        self::assertSame('swap', $playerOptions['audioDescriptionMode']);
    }

    #[Test]
    public function applyTrackDependentOptionsKeepsFloatingPlayerOutOfPlaylists(): void
    {
        $playerOptions = [];
        $trackResult = [
            'isPlaylist' => true,
            'tracks' => [['title' => 'A'], ['title' => 'B']],
            'records' => [
                ['enable_floating_player' => 1, 'media_type' => 'video'],
                ['media_type' => 'video'],
            ],
        ];

        $this->subject->applyTrackDependentOptions($playerOptions, $trackResult);

        self::assertArrayNotHasKey('floating', $playerOptions);
    }

    #[Test]
    public function buildPlaylistDataKeepsThePlayerPanelForTheCardLayout(): void
    {
        $result = $this->subject->buildPlaylistData($this->playlistTrackResult(), $this->playlistPlayerOptions(), 'card');

        self::assertTrue($result['playlistData']['options']['showPanel']);
        self::assertArrayNotHasKey('playlistToggleButton', $result['optionOverrides']);
    }

    /**
     * The episode list is the track list in that layout, so the player's own
     * panel — and the control-bar button that opens it — must stay out.
     */
    #[Test]
    public function buildPlaylistDataSuppressesThePlayerPanelForTheEpisodesLayout(): void
    {
        $result = $this->subject->buildPlaylistData($this->playlistTrackResult(), $this->playlistPlayerOptions(), 'episodes');

        self::assertFalse($result['playlistData']['options']['showPanel']);
        self::assertFalse($result['optionOverrides']['playlistToggleButton']);
    }

    /**
     * Layouts without an episode list have nowhere else to put a download, and
     * the player resolves the file per track — so one button serves them all.
     */
    #[Test]
    public function buildPlaylistDataEnablesTheDownloadButtonWhenATrackOffersAFile(): void
    {
        $trackResult = $this->playlistTrackResult();
        $trackResult['tracks'][1]['downloadUrl'] = '/fileadmin/b.mp3';

        $result = $this->subject->buildPlaylistData($trackResult, $this->playlistPlayerOptions(), 'default');

        self::assertTrue($result['optionOverrides']['downloadButton']);
    }

    #[Test]
    public function buildPlaylistDataLeavesTheDownloadButtonOffWithoutADownloadableTrack(): void
    {
        $result = $this->subject->buildPlaylistData($this->playlistTrackResult(), $this->playlistPlayerOptions(), 'default');

        self::assertArrayNotHasKey('downloadButton', $result['optionOverrides']);
    }

    #[Test]
    public function buildPlaylistDataLeavesTheDownloadToTheRowsInTheEpisodesLayout(): void
    {
        $trackResult = $this->playlistTrackResult();
        $trackResult['tracks'][0]['downloadUrl'] = '/fileadmin/a.mp3';

        $result = $this->subject->buildPlaylistData($trackResult, $this->playlistPlayerOptions(), 'episodes');

        self::assertArrayNotHasKey('downloadButton', $result['optionOverrides']);
    }

    #[Test]
    public function buildPlaylistDataStaysEmptyForASingleTrack(): void
    {
        $result = $this->subject->buildPlaylistData(
            ['isPlaylist' => false, 'tracks' => [['title' => 'A']]],
            $this->playlistPlayerOptions(),
            'default'
        );

        self::assertNull($result['playlistData']);
        self::assertSame([], $result['optionOverrides']);
    }

    /**
     * @return array<string, mixed>
     */
    private function playlistTrackResult(): array
    {
        return [
            'isPlaylist' => true,
            'tracks' => [['title' => 'A'], ['title' => 'B']],
            'isMixedPlaylist' => false,
            'hasExternalMedia' => false,
            'externalServiceTypes' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function playlistPlayerOptions(): array
    {
        return ['autoplay' => false, 'autoAdvance' => true, 'loop' => false];
    }
}
