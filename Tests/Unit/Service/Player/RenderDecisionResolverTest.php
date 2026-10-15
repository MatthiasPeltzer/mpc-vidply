<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service\Player;

use Mpc\MpcVidply\Enums\RenderMode;
use Mpc\MpcVidply\Service\Player\RenderDecisionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RenderDecisionResolver::class)]
final class RenderDecisionResolverTest extends TestCase
{
    private RenderDecisionResolver $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new RenderDecisionResolver();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{tracks: list<array<string, mixed>>, records: list<array<string, mixed>>, mediaType: ?string, hasExternalMedia: bool, hasLocalMedia: bool, externalServiceTypes: list<string>, isPlaylist: bool, isMixedPlaylist: bool}
     */
    private static function trackResult(array $overrides): array
    {
        /** @var array{tracks: list<array<string, mixed>>, records: list<array<string, mixed>>, mediaType: ?string, hasExternalMedia: bool, hasLocalMedia: bool, externalServiceTypes: list<string>, isPlaylist: bool, isMixedPlaylist: bool} */
        return array_replace([
            'tracks' => [],
            'records' => [],
            'mediaType' => 'video',
            'hasExternalMedia' => false,
            'hasLocalMedia' => false,
            'externalServiceTypes' => [],
            'isPlaylist' => false,
            'isMixedPlaylist' => false,
        ], $overrides);
    }

    #[Test]
    public function resolveServiceTypeReturnsExternalServiceForSingleExternalTrack(): void
    {
        $trackResult = self::trackResult(['tracks' => [['type' => 'youtube']]]);

        self::assertSame('youtube', $this->subject->resolveServiceType($trackResult));
    }

    #[Test]
    public function resolveServiceTypeReturnsNullForLocalMedia(): void
    {
        $trackResult = self::trackResult(['tracks' => [['type' => 'video/mp4']]]);

        self::assertNull($this->subject->resolveServiceType($trackResult));
    }

    #[Test]
    public function resolveServiceTypeReturnsNullForPlaylist(): void
    {
        $trackResult = self::trackResult([
            'isPlaylist' => true,
            'tracks' => [['type' => 'youtube'], ['type' => 'vimeo']],
        ]);

        self::assertNull($this->subject->resolveServiceType($trackResult));
    }

    #[Test]
    public function resolveEffectiveMediaTypeDowngradesToAudioWhenOnlyAudioTracks(): void
    {
        $trackResult = self::trackResult(['tracks' => [['type' => 'audio/mpeg']]]);

        self::assertSame('audio', $this->subject->resolveEffectiveMediaType($trackResult));
    }

    #[Test]
    public function resolveEffectiveMediaTypeKeepsVideoWhenVideoTrackPresent(): void
    {
        $trackResult = self::trackResult(['tracks' => [['type' => 'audio/mpeg'], ['type' => 'video/mp4']]]);

        self::assertSame('video', $this->subject->resolveEffectiveMediaType($trackResult));
    }

    #[Test]
    public function resolveEffectiveMediaTypeHonoursExplicitTrackKind(): void
    {
        $trackResult = self::trackResult(['tracks' => [['type' => 'application/x-mpegurl', 'kind' => 'audio']]]);

        self::assertSame('audio', $this->subject->resolveEffectiveMediaType($trackResult));
    }

    #[Test]
    public function determineRenderModeFallsBackToVideoWhenNoTracks(): void
    {
        self::assertSame(RenderMode::Video, $this->subject->determineRenderMode(null, self::trackResult([]), 'video'));
    }

    #[Test]
    public function determineRenderModeUsesPrivacyForSingleExternalService(): void
    {
        $trackResult = self::trackResult(['tracks' => [['type' => 'youtube']], 'hasExternalMedia' => true]);

        self::assertSame(RenderMode::Privacy, $this->subject->determineRenderMode('youtube', $trackResult, 'video'));
    }

    #[Test]
    public function determineRenderModeUsesMixedPlaylistForExternalPlaylist(): void
    {
        $trackResult = self::trackResult([
            'tracks' => [['type' => 'youtube'], ['type' => 'video/mp4']],
            'isPlaylist' => true,
            'hasExternalMedia' => true,
        ]);

        self::assertSame(RenderMode::MixedPlaylist, $this->subject->determineRenderMode(null, $trackResult, 'video'));
    }

    #[Test]
    public function determineRenderModeUsesAudioForResolvedAudio(): void
    {
        $trackResult = self::trackResult(['tracks' => [['type' => 'audio/mpeg']]]);

        self::assertSame(RenderMode::Audio, $this->subject->determineRenderMode(null, $trackResult, 'audio'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: ?string, 2: array<string, bool>}>
     */
    public static function assetFlagProvider(): array
    {
        return [
            'local single video' => [
                ['tracks' => [['type' => 'video/mp4']]],
                null,
                ['needsPrivacyLayer' => false, 'needsVidPlay' => true, 'needsPlaylist' => true, 'needsHLS' => false, 'needsDASH' => false],
            ],
            'single external video only needs the privacy layer' => [
                ['tracks' => [['type' => 'youtube']], 'hasExternalMedia' => true],
                'youtube',
                ['needsPrivacyLayer' => true, 'needsVidPlay' => false, 'needsPlaylist' => false, 'needsHLS' => false, 'needsDASH' => false],
            ],
            'hls source pulls in hls.js' => [
                ['tracks' => [['type' => 'application/x-mpegurl']]],
                null,
                ['needsPrivacyLayer' => false, 'needsVidPlay' => true, 'needsHLS' => true, 'needsDASH' => false],
            ],
            'dash inside an alternative source' => [
                ['tracks' => [['type' => 'video/mp4', 'sources' => [['type' => 'application/dash+xml']]]]],
                null,
                ['needsPrivacyLayer' => false, 'needsVidPlay' => true, 'needsHLS' => false, 'needsDASH' => true],
            ],
            'external playlist needs the privacy layer' => [
                ['isPlaylist' => true, 'hasExternalMedia' => true, 'tracks' => [['type' => 'youtube'], ['type' => 'video/mp4']]],
                null,
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
    public function resolveAssetFlagsLoadsOnlyWhatTheTracksNeed(array $trackResult, ?string $serviceType, array $expected): void
    {
        $flags = $this->subject->resolveAssetFlags($serviceType, self::trackResult($trackResult));

        foreach ($expected as $flag => $value) {
            self::assertSame($value, $flags[$flag], $flag);
        }
    }
}
