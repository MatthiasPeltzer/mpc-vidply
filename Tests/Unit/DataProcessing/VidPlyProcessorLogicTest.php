<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\DataProcessing;

use Mpc\MpcVidply\DataProcessing\VidPlyProcessor;
use Mpc\MpcVidply\Enums\RenderMode;
use Mpc\MpcVidply\Service\MediaCategoryResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure decision/mapping helpers of {@see VidPlyProcessor}.
 *
 * These methods are private and free of the (heavy) constructor services, so
 * the subject is instantiated without invoking its constructor and the helpers
 * are reached via reflection.
 */
final class VidPlyProcessorLogicTest extends TestCase
{
    private VidPlyProcessor $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = (new \ReflectionClass(VidPlyProcessor::class))->newInstanceWithoutConstructor();
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(VidPlyProcessor::class, $method))->invoke($this->subject, ...$args);
    }

    /**
     * @param array<string, mixed> $playerOptions
     * @param array<string, mixed> $trackResult
     */
    private function invokeApplyTrackDependentOptions(array &$playerOptions, array $trackResult): void
    {
        $method = new \ReflectionMethod(VidPlyProcessor::class, 'applyTrackDependentOptions');
        $method->invokeArgs($this->subject, [&$playerOptions, $trackResult]);
    }

    /**
     * Reflection drops by-reference semantics for spread arguments, so methods
     * that write into their first parameter need `invokeArgs`.
     *
     * @param array<string, mixed> $subjectArgument
     */
    private function invokeByReference(string $method, array &$subjectArgument, mixed ...$args): void
    {
        (new \ReflectionMethod(VidPlyProcessor::class, $method))
            ->invokeArgs($this->subject, [&$subjectArgument, ...$args]);
    }

    #[Test]
    public function buildPlayerOptionsDefaultsWhenNoBitsSet(): void
    {
        $options = $this->invoke('buildPlayerOptions', []);

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
    }

    #[Test]
    public function buildPlayerOptionsDecodesBitmask(): void
    {
        // CONTROLS (8) + KEYBOARD (64) + AUTO_ADVANCE (256) = 328 (the documented default)
        $options = $this->invoke('buildPlayerOptions', [
            'tx_mpcvidply_options' => 328,
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
    public function buildPlayerOptionsAutoplayDisablesDeferLoad(): void
    {
        // AUTOPLAY (1) + CONTROLS (8)
        $options = $this->invoke('buildPlayerOptions', ['tx_mpcvidply_options' => 9]);

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
     * @param array<int, array<string, mixed>> $tracks
     */
    #[Test]
    #[DataProvider('mseStreamProvider')]
    public function hasMseStreamDetectsStreamingProtocols(array $tracks, bool $expected): void
    {
        self::assertSame($expected, $this->invoke('hasMseStream', $tracks));
    }

    #[Test]
    public function resolveServiceTypeReturnsExternalServiceForSingleExternalTrack(): void
    {
        $trackResult = [
            'isPlaylist' => false,
            'tracks' => [['type' => 'youtube']],
        ];

        self::assertSame('youtube', $this->invoke('resolveServiceType', $trackResult));
    }

    #[Test]
    public function resolveServiceTypeReturnsNullForLocalMedia(): void
    {
        $trackResult = [
            'isPlaylist' => false,
            'tracks' => [['type' => 'video/mp4']],
        ];

        self::assertNull($this->invoke('resolveServiceType', $trackResult));
    }

    #[Test]
    public function resolveServiceTypeReturnsNullForPlaylist(): void
    {
        $trackResult = [
            'isPlaylist' => true,
            'tracks' => [['type' => 'youtube'], ['type' => 'vimeo']],
        ];

        self::assertNull($this->invoke('resolveServiceType', $trackResult));
    }

    #[Test]
    public function resolveEffectiveMediaTypeDowngradesToAudioWhenOnlyAudioTracks(): void
    {
        $trackResult = [
            'mediaType' => 'video',
            'tracks' => [['type' => 'audio/mpeg']],
        ];

        self::assertSame('audio', $this->invoke('resolveEffectiveMediaType', $trackResult));
    }

    #[Test]
    public function resolveEffectiveMediaTypeKeepsVideoWhenVideoTrackPresent(): void
    {
        $trackResult = [
            'mediaType' => 'video',
            'tracks' => [['type' => 'audio/mpeg'], ['type' => 'video/mp4']],
        ];

        self::assertSame('video', $this->invoke('resolveEffectiveMediaType', $trackResult));
    }

    #[Test]
    public function determineRenderModeFallsBackToVideoWhenNoTracks(): void
    {
        $trackResult = ['tracks' => [], 'isPlaylist' => false, 'hasExternalMedia' => false];

        self::assertSame(RenderMode::Video, $this->invoke('determineRenderMode', null, $trackResult, 'video'));
    }

    #[Test]
    public function determineRenderModeUsesPrivacyForSingleExternalService(): void
    {
        $trackResult = ['tracks' => [['type' => 'youtube']], 'isPlaylist' => false, 'hasExternalMedia' => true];

        self::assertSame(RenderMode::Privacy, $this->invoke('determineRenderMode', 'youtube', $trackResult, 'video'));
    }

    #[Test]
    public function determineRenderModeUsesMixedPlaylistForExternalPlaylist(): void
    {
        $trackResult = ['tracks' => [['type' => 'youtube'], ['type' => 'video/mp4']], 'isPlaylist' => true, 'hasExternalMedia' => true];

        self::assertSame(RenderMode::MixedPlaylist, $this->invoke('determineRenderMode', null, $trackResult, 'video'));
    }

    #[Test]
    public function determineRenderModeUsesAudioForResolvedAudio(): void
    {
        $trackResult = ['tracks' => [['type' => 'audio/mpeg']], 'isPlaylist' => false, 'hasExternalMedia' => false];

        self::assertSame(RenderMode::Audio, $this->invoke('determineRenderMode', null, $trackResult, 'audio'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mimeTypeProvider(): array
    {
        return [
            'mp3' => ['https://cdn.example.com/a.mp3', 'audio/mpeg'],
            'ogg' => ['https://cdn.example.com/a.ogg', 'audio/ogg'],
            'wav' => ['https://cdn.example.com/a.wav', 'audio/wav'],
            'm3u8 hls' => ['https://cdn.example.com/a.m3u8', 'application/vnd.apple.mpegurl'],
            'mpd dash' => ['https://cdn.example.com/a.mpd', 'application/dash+xml'],
            'mp4' => ['https://cdn.example.com/a.mp4', 'video/mp4'],
            'webm' => ['https://cdn.example.com/a.webm', 'video/webm'],
            'unknown falls back to octet-stream' => ['https://cdn.example.com/a.bin', 'application/octet-stream'],
        ];
    }

    #[Test]
    #[DataProvider('mimeTypeProvider')]
    public function inferMimeTypeFromUrlMapsExtensions(string $url, string $expected): void
    {
        self::assertSame($expected, $this->invoke('inferMimeTypeFromUrlCached', $url, ''));
    }

    #[Test]
    public function inferMimeTypeFromUrlUsesFallbackForUnknownExtension(): void
    {
        self::assertSame('video/quicktime', $this->invoke('inferMimeTypeFromUrlCached', 'https://cdn.example.com/a.mov', 'video/quicktime'));
    }

    #[Test]
    public function stripControlCharsRemovesControlBytesButKeepsTabsAndNewlines(): void
    {
        $input = "Hello\x00\x07World\tTabbed\nNewline";

        self::assertSame("HelloWorld\tTabbed\nNewline", $this->invoke('stripControlChars', $input));
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

        $this->invokeApplyTrackDependentOptions($playerOptions, $trackResult);

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

        $this->invokeApplyTrackDependentOptions($playerOptions, $trackResult);

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

        $this->invokeApplyTrackDependentOptions($playerOptions, $trackResult);

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

        $this->invokeApplyTrackDependentOptions($playerOptions, $trackResult);

        self::assertArrayNotHasKey('floating', $playerOptions);
    }

    #[Test]
    public function buildBaseTrackDataMapsHideHelpButtonFlag(): void
    {
        $track = $this->invoke('buildBaseTrackData', [
            'title' => 'Example',
            'hide_help_button' => 1,
        ]);

        self::assertTrue($track['hideHelpButton']);
    }

    private function setProperty(string $name, mixed $value): void
    {
        (new \ReflectionProperty(VidPlyProcessor::class, $name))->setValue($this->subject, $value);
    }

    #[Test]
    #[DataProvider('layoutProvider')]
    public function resolveLayoutFallsBackToDefaultForUnknownValues(mixed $stored, string $expected): void
    {
        self::assertSame($expected, $this->invoke('resolveLayout', ['tx_mpcvidply_layout' => $stored]));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function layoutProvider(): array
    {
        return [
            'card' => ['card', 'card'],
            'episodes' => ['episodes', 'episodes'],
            'explicit default' => ['default', 'default'],
            'unknown value' => ['podcast', 'default'],
            'empty value' => ['', 'default'],
        ];
    }

    #[Test]
    public function resolveLayoutDefaultsWhenFieldIsMissing(): void
    {
        self::assertSame('default', $this->invoke('resolveLayout', []));
    }

    #[Test]
    #[DataProvider('episodeSortProvider')]
    public function resolveEpisodeSortFallsBackToManualOrder(mixed $stored, string $expected): void
    {
        self::assertSame($expected, $this->invoke('resolveEpisodeSort', ['tx_mpcvidply_episode_sort' => $stored]));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function episodeSortProvider(): array
    {
        return [
            'manual' => ['sorting', 'sorting'],
            'newest first' => ['date_desc', 'date_desc'],
            'oldest first' => ['date_asc', 'date_asc'],
            'title' => ['title_asc', 'title_asc'],
            'unknown value' => ['duration_desc', 'sorting'],
            'empty value' => ['', 'sorting'],
        ];
    }

    #[Test]
    public function resolveEpisodeSortDefaultsWhenFieldIsMissing(): void
    {
        self::assertSame('sorting', $this->invoke('resolveEpisodeSort', []));
    }

    /**
     * @param list<int> $expectedIndexes
     */
    #[Test]
    #[DataProvider('episodeSortOrderProvider')]
    public function sortEpisodesReordersOnlyTheDisplayList(string $sort, array $expectedIndexes): void
    {
        $sorted = $this->invoke('sortEpisodes', self::episodeFixtures(), $sort);

        self::assertSame($expectedIndexes, array_column($sorted, 'index'));
    }

    /**
     * @return array<string, array{0: string, 1: list<int>}>
     */
    public static function episodeSortOrderProvider(): array
    {
        return [
            // Undated episodes stay last in both date modes rather than counting as "oldest".
            'manual keeps the editor order' => ['sorting', [0, 1, 2, 3]],
            'newest first' => ['date_desc', [1, 0, 2, 3]],
            'oldest first' => ['date_asc', [2, 0, 1, 3]],
            'title ascending' => ['title_asc', [3, 2, 1, 0]],
        ];
    }

    #[Test]
    public function sortEpisodesFallsBackToTheTrackIndexOnEqualKeys(): void
    {
        $episodes = [
            ['index' => 0, 'title' => 'Same', 'dateIso' => '2024-03-01'],
            ['index' => 1, 'title' => 'same', 'dateIso' => '2024-03-01'],
        ];

        self::assertSame([0, 1], array_column($this->invoke('sortEpisodes', $episodes, 'title_asc'), 'index'));
        self::assertSame([0, 1], array_column($this->invoke('sortEpisodes', $episodes, 'date_desc'), 'index'));
    }

    #[Test]
    public function resolveLeadEpisodeReturnsTheFirstTrackRegardlessOfListOrder(): void
    {
        $episodes = $this->invoke('sortEpisodes', self::episodeFixtures(), 'title_asc');

        $lead = $this->invoke('resolveLeadEpisode', $episodes);

        self::assertIsArray($lead);
        self::assertSame(0, $lead['index']);
    }

    #[Test]
    public function resolveLeadEpisodeReturnsNullWithoutEpisodes(): void
    {
        self::assertNull($this->invoke('resolveLeadEpisode', []));
    }

    #[Test]
    public function resolveEpisodeListSettingsClampsThePerPageValue(): void
    {
        $settings = $this->invoke('resolveEpisodeListSettings', ['tx_mpcvidply_episode_per_page' => 0], 'episodes', []);
        self::assertSame(1, $settings['paginationPerPage']);

        $settings = $this->invoke('resolveEpisodeListSettings', ['tx_mpcvidply_episode_per_page' => 5000], 'episodes', []);
        self::assertSame(200, $settings['paginationPerPage']);

        $settings = $this->invoke('resolveEpisodeListSettings', [], 'episodes', []);
        self::assertSame(10, $settings['paginationPerPage']);
    }

    #[Test]
    public function resolveEpisodeListSettingsActivatesPaginationOnlyAboveThePageSize(): void
    {
        $data = ['tx_mpcvidply_episode_pagination' => 1, 'tx_mpcvidply_episode_per_page' => 2];

        $settings = $this->invoke('resolveEpisodeListSettings', $data, 'episodes', array_fill(0, 2, ['index' => 0]));
        self::assertTrue($settings['paginationEnabled']);
        self::assertFalse($settings['paginationActive']);

        $settings = $this->invoke('resolveEpisodeListSettings', $data, 'episodes', array_fill(0, 3, ['index' => 0]));
        self::assertTrue($settings['paginationActive']);
    }

    #[Test]
    public function resolveEpisodeListSettingsDisablesPaginationOutsideTheEpisodeList(): void
    {
        $data = ['tx_mpcvidply_episode_pagination' => 1, 'tx_mpcvidply_episode_per_page' => 1];

        $settings = $this->invoke('resolveEpisodeListSettings', $data, 'card', array_fill(0, 5, ['index' => 0]));

        self::assertFalse($settings['paginationEnabled']);
        self::assertFalse($settings['paginationActive']);
    }

    #[Test]
    public function resolveEpisodeListSettingsHonoursTheDisabledToggle(): void
    {
        $data = ['tx_mpcvidply_episode_pagination' => 0, 'tx_mpcvidply_episode_per_page' => 1];

        $settings = $this->invoke('resolveEpisodeListSettings', $data, 'episodes', array_fill(0, 5, ['index' => 0]));

        self::assertFalse($settings['paginationEnabled']);
        self::assertFalse($settings['paginationActive']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function episodeFixtures(): array
    {
        return [
            ['index' => 0, 'title' => 'Charlie', 'dateIso' => '2024-02-01'],
            ['index' => 1, 'title' => 'bravo', 'dateIso' => '2024-03-01'],
            ['index' => 2, 'title' => 'Alpha', 'dateIso' => '2024-01-01'],
            ['index' => 3, 'title' => 'Alfa', 'dateIso' => ''],
        ];
    }

    #[Test]
    public function formatPublishDateReturnsEmptyStringForUnsetDate(): void
    {
        self::assertSame('', $this->invoke('formatPublishDate', 0));
    }

    #[Test]
    public function formatPublishDateUsesTheSiteLocale(): void
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            self::markTestSkipped('ext-intl is not available.');
        }

        // 2021-05-18 00:00 UTC — the value TYPO3 stores for a date-only field.
        $timestamp = 1621296000;
        $this->setProperty('formattingLocale', 'de-DE');

        self::assertSame('18. Mai 2021', $this->invoke('formatPublishDate', $timestamp));

        $this->setProperty('formattingLocale', 'en-US');

        self::assertSame('May 18, 2021', $this->invoke('formatPublishDate', $timestamp));
    }

    #[Test]
    public function formatPublishDateStaysOnTheStoredDayWithoutALocale(): void
    {
        $this->setProperty('formattingLocale', null);

        self::assertSame('2021-05-18', $this->invoke('formatPublishDate', 1621296000));
    }

    #[Test]
    public function buildBaseTrackDataAddsFormattedDateAndEpisodeNumber(): void
    {
        $this->setProperty('formattingLocale', 'en-US');

        $track = $this->invoke('buildBaseTrackData', [
            'title' => 'Episode 11',
            'publish_date' => 1621296000,
            'episode_number' => ' 11 ',
        ]);

        self::assertSame('11', $track['episodeNumber']);
        self::assertNotSame('', $track['date']);
        self::assertStringContainsString('2021', $track['date']);
    }

    #[Test]
    public function buildBaseTrackDataOmitsEmptyDateAndEpisodeNumber(): void
    {
        $track = $this->invoke('buildBaseTrackData', [
            'title' => 'Episode 11',
            'publish_date' => 0,
            'episode_number' => '   ',
        ]);

        self::assertArrayNotHasKey('date', $track);
        self::assertArrayNotHasKey('episodeNumber', $track);
    }

    #[Test]
    public function buildEpisodeDataExposesServerRenderedMetadata(): void
    {
        $this->setProperty('formattingLocale', 'en-US');
        // No poster references — avoids touching the (unconstructed) FileRepository.
        $this->setProperty('fileReferencesByMediaUid', [7 => ['poster' => []]]);

        $episodes = $this->invoke(
            'buildEpisodeData',
            [[
                'uid' => 7,
                'title' => 'Episode 11',
                'artist' => 'MP Core',
                'description' => 'Show notes',
                'duration' => 3725,
                'publish_date' => 1621296000,
                'episode_number' => '11',
            ]],
            [[['uid' => 3, 'title' => 'Politics']]]
        );

        self::assertCount(1, $episodes);
        self::assertSame(0, $episodes[0]['index']);
        self::assertSame('Episode 11', $episodes[0]['title']);
        self::assertSame('11', $episodes[0]['episodeNumber']);
        self::assertSame('2021-05-18', $episodes[0]['dateIso']);
        self::assertSame('1:02:05', $episodes[0]['durationFormatted']);
        self::assertSame([['uid' => 3, 'title' => 'Politics']], $episodes[0]['categories']);
        self::assertNull($episodes[0]['posterReferenceUid']);
        self::assertSame('Episode 11', $episodes[0]['posterAlt']);
    }

    #[Test]
    public function buildEpisodeDataLeavesDateFieldsEmptyWhenUnset(): void
    {
        $this->setProperty('fileReferencesByMediaUid', [7 => ['poster' => []]]);

        $episodes = $this->invoke('buildEpisodeData', [[
            'uid' => 7,
            'title' => 'Episode 12',
        ]], []);

        self::assertSame('', $episodes[0]['dateFormatted']);
        self::assertSame('', $episodes[0]['dateIso']);
        self::assertSame('', $episodes[0]['durationFormatted']);
        self::assertSame([], $episodes[0]['categories']);
    }

    #[Test]
    public function resolveEpisodesForLayoutBuildsNothingForTheDefaultLayout(): void
    {
        self::assertSame([], $this->invoke('resolveEpisodesForLayout', 'default', $this->episodesTrackResult(1), 0, []));
    }

    #[Test]
    public function resolveEpisodesForLayoutOnlyBuildsTheFirstRecordForTheCardLayout(): void
    {
        $this->prepareCategoryResolver();

        $episodes = $this->invoke('resolveEpisodesForLayout', 'card', $this->episodesTrackResult(3), 0, []);

        self::assertCount(1, $episodes);
        self::assertSame('A', $episodes[0]['title']);
    }

    #[Test]
    public function resolveEpisodesForLayoutBuildsEveryRecordForTheEpisodesLayout(): void
    {
        $this->prepareCategoryResolver();

        $episodes = $this->invoke('resolveEpisodesForLayout', 'episodes', $this->episodesTrackResult(3), 0, []);

        self::assertCount(3, $episodes);
        self::assertSame([0, 1, 2], array_column($episodes, 'index'));
        self::assertSame(['A', 'B', 'C'], array_column($episodes, 'title'));
    }

    #[Test]
    public function resolveEpisodesForLayoutAppliesTheConfiguredOrderToTheListOnly(): void
    {
        $this->prepareCategoryResolver();

        $trackResult = $this->episodesTrackResult(3);
        $trackResult['records'][0]['title'] = 'Zulu';

        $episodes = $this->invoke(
            'resolveEpisodesForLayout',
            'episodes',
            $trackResult,
            0,
            ['tx_mpcvidply_episode_sort' => 'title_asc']
        );

        self::assertSame(['B', 'C', 'Zulu'], array_column($episodes, 'title'));
        // The track indexes travel with the rows, so playback keeps its order.
        self::assertSame([1, 2, 0], array_column($episodes, 'index'));
    }

    /**
     * Every episode is on the page in that layout, so each row offers its own
     * file — no episode has to be selected first to be saved.
     */
    #[Test]
    public function resolveEpisodesForLayoutOffersADownloadPerEpisodeForPlaylists(): void
    {
        $this->prepareCategoryResolver();

        $trackResult = $this->episodesTrackResult(2);
        $trackResult['records'][0]['allow_download'] = 1;
        $trackResult['tracks'][0] = ['src' => '/fileadmin/a.mp3', 'type' => 'audio/mpeg'];

        $episodes = $this->invoke('resolveEpisodesForLayout', 'episodes', $trackResult, 0, []);

        self::assertSame('/fileadmin/a.mp3', $episodes[0]['downloadUrl']);
        self::assertSame('MP3', $episodes[0]['downloadInfo']);
        self::assertSame('', $episodes[1]['downloadUrl']);
    }

    #[Test]
    public function resolveEpisodesForLayoutLeavesTheDownloadToThePlayerForASingleMedium(): void
    {
        $this->prepareCategoryResolver();

        $trackResult = $this->episodesTrackResult(1);
        $trackResult['isPlaylist'] = false;
        $trackResult['records'][0]['allow_download'] = 1;
        $trackResult['tracks'][0] = ['src' => '/fileadmin/a.mp3', 'type' => 'audio/mpeg'];

        $episodes = $this->invoke('resolveEpisodesForLayout', 'card', $trackResult, 0, []);

        self::assertSame('', $episodes[0]['downloadUrl']);
    }

    /**
     * The card shows one episode while the player may be on another, so a link
     * printed there would age. The player's button follows the track instead.
     */
    #[Test]
    public function resolveEpisodesForLayoutLeavesTheDownloadToThePlayerForTheCardLayout(): void
    {
        $this->prepareCategoryResolver();

        $trackResult = $this->episodesTrackResult(3);
        $trackResult['records'][0]['allow_download'] = 1;
        $trackResult['tracks'][0] = ['src' => '/fileadmin/a.mp3', 'type' => 'audio/mpeg'];

        $episodes = $this->invoke('resolveEpisodesForLayout', 'card', $trackResult, 0, []);

        self::assertSame('', $episodes[0]['downloadUrl']);
    }

    #[Test]
    public function enrichTrackWithDownloadDataOffersTheFileWithItsFormat(): void
    {
        $track = ['allowDownload' => true, 'src' => '/fileadmin/a.mp3', 'type' => 'audio/mpeg'];

        $this->invokeByReference('enrichTrackWithDownloadData', $track, 0);

        self::assertSame('/fileadmin/a.mp3', $track['downloadUrl']);
        self::assertSame('MP3', $track['downloadFormat']);
        // Records without a uid have no measurable file behind them.
        self::assertArrayNotHasKey('downloadFileSize', $track);
    }

    #[Test]
    public function enrichTrackWithDownloadDataPrefersAProgressiveSourceOverAManifest(): void
    {
        $track = [
            'allowDownload' => true,
            'src' => 'https://cdn.example.com/a.m3u8',
            'type' => 'application/x-mpegurl',
            'sources' => [
                ['src' => 'https://cdn.example.com/a.m3u8', 'type' => 'application/x-mpegurl'],
                ['src' => 'https://cdn.example.com/a.mp4', 'type' => 'video/mp4'],
            ],
        ];

        $this->invokeByReference('enrichTrackWithDownloadData', $track, 0);

        self::assertSame('https://cdn.example.com/a.mp4', $track['downloadUrl']);
        self::assertSame('MP4', $track['downloadFormat']);
    }

    #[Test]
    public function enrichTrackWithDownloadDataStaysEmptyWithoutTheRecordsPermission(): void
    {
        $track = ['src' => '/fileadmin/a.mp3', 'type' => 'audio/mpeg'];

        $this->invokeByReference('enrichTrackWithDownloadData', $track, 0);

        self::assertArrayNotHasKey('downloadUrl', $track);
    }

    #[Test]
    public function resolveEpisodeDownloadPrefersAProgressiveSourceOverAManifest(): void
    {
        $download = $this->invoke(
            'resolveEpisodeDownload',
            ['uid' => 0, 'allow_download' => 1],
            [
                'src' => 'https://cdn.example.com/a.m3u8',
                'type' => 'application/x-mpegurl',
                'sources' => [
                    ['src' => 'https://cdn.example.com/a.m3u8', 'type' => 'application/x-mpegurl'],
                    ['src' => 'https://cdn.example.com/a.mp4', 'type' => 'video/mp4'],
                ],
            ]
        );

        self::assertSame('https://cdn.example.com/a.mp4', $download['url']);
        self::assertSame('MP4', $download['info']);
    }

    #[Test]
    public function resolveEpisodeDownloadStaysEmptyWithoutTheRecordsPermission(): void
    {
        $download = $this->invoke(
            'resolveEpisodeDownload',
            ['uid' => 0],
            ['src' => '/fileadmin/a.mp3', 'type' => 'audio/mpeg']
        );

        self::assertSame(['url' => '', 'info' => ''], $download);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function fileSizeProvider(): array
    {
        return [
            'unknown size' => [0, ''],
            'bytes stay whole' => [512, '512 B'],
            'kilobytes stay whole' => [2048, '2 KB'],
            'megabytes get one decimal' => [7_759_462, '7.4 MB'],
            'gigabytes get one decimal' => [3_221_225_472, '3.0 GB'],
        ];
    }

    #[Test]
    #[DataProvider('fileSizeProvider')]
    public function formatFileSizeMatchesThePlayersLabels(int $bytes, string $expected): void
    {
        $this->setProperty('formattingLocale', 'en-US');

        self::assertSame($expected, $this->invoke('formatFileSize', $bytes));
    }

    #[Test]
    public function formatFileSizeUsesTheSiteLocale(): void
    {
        if (!class_exists(\NumberFormatter::class)) {
            self::markTestSkipped('ext-intl is not available.');
        }

        $this->setProperty('formattingLocale', 'de-DE');

        self::assertSame('7,4 MB', $this->invoke('formatFileSize', 7_759_462));
    }

    #[Test]
    public function buildPlaylistDataKeepsThePlayerPanelForTheCardLayout(): void
    {
        $result = $this->invoke('buildPlaylistData', $this->playlistTrackResult(), $this->playlistPlayerOptions(), 'card');

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
        $result = $this->invoke('buildPlaylistData', $this->playlistTrackResult(), $this->playlistPlayerOptions(), 'episodes');

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

        $result = $this->invoke('buildPlaylistData', $trackResult, $this->playlistPlayerOptions(), 'default');

        self::assertTrue($result['optionOverrides']['downloadButton']);
    }

    #[Test]
    public function buildPlaylistDataLeavesTheDownloadButtonOffWithoutADownloadableTrack(): void
    {
        $result = $this->invoke('buildPlaylistData', $this->playlistTrackResult(), $this->playlistPlayerOptions(), 'default');

        self::assertArrayNotHasKey('downloadButton', $result['optionOverrides']);
    }

    #[Test]
    public function buildPlaylistDataLeavesTheDownloadToTheRowsInTheEpisodesLayout(): void
    {
        $trackResult = $this->playlistTrackResult();
        $trackResult['tracks'][0]['downloadUrl'] = '/fileadmin/a.mp3';

        $result = $this->invoke('buildPlaylistData', $trackResult, $this->playlistPlayerOptions(), 'episodes');

        self::assertArrayNotHasKey('downloadButton', $result['optionOverrides']);
    }

    /**
     * Records without a uid resolve to no categories and no poster, which keeps
     * the episode builder away from the (unconstructed) database services.
     */
    private function prepareCategoryResolver(): void
    {
        $this->setProperty(
            'mediaCategoryResolver',
            (new \ReflectionClass(MediaCategoryResolver::class))->newInstanceWithoutConstructor()
        );
    }

    /**
     * Track result of a playlist of $count media, titled "A", "B", "C", … with
     * records that carry no uid so poster and category lookups stay away from the
     * (unconstructed) database services.
     *
     * @return array<string, mixed>
     */
    private function episodesTrackResult(int $count): array
    {
        $records = [];
        $tracks = [];
        for ($index = 0; $index < $count; $index++) {
            $title = chr(ord('A') + $index);
            $records[] = ['title' => $title];
            $tracks[] = ['title' => $title, 'src' => '/fileadmin/' . strtolower($title) . '.mp3', 'type' => 'audio/mpeg'];
        }

        return [
            'isPlaylist' => $count > 1,
            'tracks' => $tracks,
            'records' => $records,
        ];
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
