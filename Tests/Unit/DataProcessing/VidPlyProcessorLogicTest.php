<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\DataProcessing;

use Mpc\MpcVidply\DataProcessing\VidPlyProcessor;
use Mpc\MpcVidply\Enums\RenderMode;
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
     * @param list<array<string, mixed>> $mediaRecords
     */
    private function invokeApplyTrackDependentOptions(
        array &$playerOptions,
        array $trackResult,
        array $mediaRecords,
    ): void {
        $method = new \ReflectionMethod(VidPlyProcessor::class, 'applyTrackDependentOptions');
        $method->invokeArgs($this->subject, [&$playerOptions, $trackResult, $mediaRecords]);
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
        $trackResult = ['isPlaylist' => false, 'tracks' => [['title' => 'Test']]];
        $mediaRecords = [['hide_help_button' => 1, 'media_type' => 'video']];

        $this->invokeApplyTrackDependentOptions($playerOptions, $trackResult, $mediaRecords);

        self::assertFalse($playerOptions['helpButton']);
    }

    #[Test]
    public function applyTrackDependentOptionsDoesNotHideHelpButtonForPlaylist(): void
    {
        $playerOptions = [];
        $trackResult = ['isPlaylist' => true, 'tracks' => [['title' => 'A'], ['title' => 'B']]];
        $mediaRecords = [['hide_help_button' => 1, 'media_type' => 'video']];

        $this->invokeApplyTrackDependentOptions($playerOptions, $trackResult, $mediaRecords);

        self::assertArrayNotHasKey('helpButton', $playerOptions);
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
        $this->setProperty('dateLocale', 'de-DE');

        self::assertSame('18. Mai 2021', $this->invoke('formatPublishDate', $timestamp));

        $this->setProperty('dateLocale', 'en-US');

        self::assertSame('May 18, 2021', $this->invoke('formatPublishDate', $timestamp));
    }

    #[Test]
    public function formatPublishDateStaysOnTheStoredDayWithoutALocale(): void
    {
        $this->setProperty('dateLocale', null);

        self::assertSame('2021-05-18', $this->invoke('formatPublishDate', 1621296000));
    }

    #[Test]
    public function buildBaseTrackDataAddsFormattedDateAndEpisodeNumber(): void
    {
        $this->setProperty('dateLocale', 'en-US');

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
        $this->setProperty('dateLocale', 'en-US');
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
}
