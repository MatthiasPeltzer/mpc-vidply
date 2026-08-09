<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\DataProcessing;

use Mpc\MpcVidply\DataProcessing\VidPlyProcessor;
use Mpc\MpcVidply\Enums\RenderMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the render decisions {@see VidPlyProcessor} still makes itself;
 * everything it delegates is covered next to the collaborator that owns it.
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
        // PHP 8.1+ exposes private methods through reflection without
        // setAccessible(), which is deprecated as of PHP 8.5.
        return (new \ReflectionMethod(VidPlyProcessor::class, $method))->invoke($this->subject, ...$args);
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
     * @return array<string, array{0: array<string, mixed>, 1: array<string, bool>}>
     */
    public static function assetFlagProvider(): array
    {
        return [
            'local single video' => [
                ['isPlaylist' => false, 'hasExternalMedia' => false, 'tracks' => [['type' => 'video/mp4']]],
                ['needsPrivacyLayer' => false, 'needsVidPlay' => true, 'needsHLS' => false, 'needsDASH' => false],
            ],
            'hls source pulls in hls.js' => [
                ['isPlaylist' => false, 'hasExternalMedia' => false, 'tracks' => [['type' => 'application/x-mpegurl']]],
                ['needsPrivacyLayer' => false, 'needsVidPlay' => true, 'needsHLS' => true, 'needsDASH' => false],
            ],
            'dash inside an alternative source' => [
                [
                    'isPlaylist' => false,
                    'hasExternalMedia' => false,
                    'tracks' => [['type' => 'video/mp4', 'sources' => [['type' => 'application/dash+xml']]]],
                ],
                ['needsPrivacyLayer' => false, 'needsVidPlay' => true, 'needsHLS' => false, 'needsDASH' => true],
            ],
            'external playlist needs the privacy layer' => [
                ['isPlaylist' => true, 'hasExternalMedia' => true, 'tracks' => [['type' => 'youtube'], ['type' => 'video/mp4']]],
                ['needsPrivacyLayer' => true, 'needsVidPlay' => true, 'needsHLS' => false, 'needsDASH' => false],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $trackResult
     * @param array<string, bool> $expected
     */
    #[Test]
    #[DataProvider('assetFlagProvider')]
    public function resolveAssetFlagsLoadsOnlyWhatTheTracksNeed(array $trackResult, array $expected): void
    {
        $serviceType = $trackResult['isPlaylist'] === false && ($trackResult['hasExternalMedia'] ?? false)
            ? 'youtube'
            : null;

        $flags = $this->invoke('resolveAssetFlags', $serviceType, $trackResult);

        foreach ($expected as $flag => $value) {
            self::assertSame($value, $flags[$flag], $flag);
        }
    }
}
