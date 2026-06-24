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
}
