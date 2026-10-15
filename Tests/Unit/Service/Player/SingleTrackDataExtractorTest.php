<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service\Player;

use Mpc\MpcVidply\Service\Player\DownloadResolver;
use Mpc\MpcVidply\Service\Player\LocaleFormatter;
use Mpc\MpcVidply\Service\Player\MediaFileRegistry;
use Mpc\MpcVidply\Service\Player\SingleTrackDataExtractor;
use Mpc\MpcVidply\Service\Player\UrlSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tracks carry no record uid here, so the download resolver never reaches FAL.
 */
#[CoversClass(SingleTrackDataExtractor::class)]
final class SingleTrackDataExtractorTest extends TestCase
{
    private SingleTrackDataExtractor $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $files = (new \ReflectionClass(MediaFileRegistry::class))->newInstanceWithoutConstructor();
        $this->subject = new SingleTrackDataExtractor(
            new UrlSanitizer(),
            new DownloadResolver($files, new LocaleFormatter())
        );
    }

    /**
     * @param list<array<string, mixed>> $tracks
     * @return array{tracks: list<array<string, mixed>>, records: list<array<string, mixed>>, mediaType: ?string, hasExternalMedia: bool, hasLocalMedia: bool, externalServiceTypes: list<string>, isPlaylist: bool, isMixedPlaylist: bool}
     */
    private static function trackResult(array $tracks, bool $isPlaylist = false): array
    {
        return [
            'tracks' => $tracks,
            'records' => [],
            'mediaType' => 'video',
            'hasExternalMedia' => false,
            'hasLocalMedia' => true,
            'externalServiceTypes' => [],
            'isPlaylist' => $isPlaylist,
            'isMixedPlaylist' => false,
        ];
    }

    #[Test]
    public function playlistsAndEmptyTrackListsYieldEmptyData(): void
    {
        $playlist = $this->subject->extract(self::trackResult([['src' => 'a.mp4', 'type' => 'video/mp4']], true));
        $empty = $this->subject->extract(self::trackResult([]));

        foreach ([$playlist, $empty] as $result) {
            self::assertSame('', $result['trackData']['videoUrl']);
            self::assertSame([], $result['trackData']['mediaFiles']);
            self::assertNull($result['trackData']['sources']);
            self::assertSame([], $result['optionOverrides']);
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: ?list<array<string, mixed>>, 3: int}>
     */
    public static function sourceProvider(): array
    {
        $mp4 = ['src' => '/fileadmin/a.mp4', 'type' => 'video/mp4'];
        $webm = ['src' => '/fileadmin/a.webm', 'type' => 'video/webm'];
        $hls = ['src' => 'https://cdn.example.com/a.m3u8', 'type' => 'application/x-mpegurl'];

        return [
            'external service is handed over as data-vidply-src' => [
                ['src' => 'https://www.youtube.com/watch?v=abc', 'type' => 'youtube'],
                'https://www.youtube.com/watch?v=abc',
                null,
                0,
            ],
            'single streaming manifest avoids a <source> child' => [
                $hls,
                'https://cdn.example.com/a.m3u8',
                null,
                0,
            ],
            'multi-source with a manifest suppresses the native fallbacks' => [
                ['src' => $mp4['src'], 'type' => 'video/mp4', 'sources' => [$mp4, $hls]],
                'https://cdn.example.com/a.m3u8',
                null,
                0,
            ],
            'multi-source without a manifest renders <source> children' => [
                ['src' => $mp4['src'], 'type' => 'video/mp4', 'sources' => [$mp4, $webm]],
                '',
                [$mp4, $webm],
                0,
            ],
            'single file becomes one media file' => [
                $mp4,
                '',
                null,
                1,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $track
     * @param ?list<array<string, mixed>> $expectedSources
     */
    #[Test]
    #[DataProvider('sourceProvider')]
    public function chooseHowTheSourceReachesThePlayer(array $track, string $expectedVideoUrl, ?array $expectedSources, int $expectedMediaFiles): void
    {
        $trackData = $this->subject->extract(self::trackResult([$track]))['trackData'];

        self::assertSame($expectedVideoUrl, $trackData['videoUrl']);
        self::assertSame($expectedSources, $trackData['sources']);
        self::assertCount($expectedMediaFiles, $trackData['mediaFiles']);
    }

    #[Test]
    public function singleFileKeepsItsUrlAndMimeType(): void
    {
        $trackData = $this->subject->extract(self::trackResult([['src' => '/fileadmin/a.mp4', 'type' => 'video/mp4']]))['trackData'];

        self::assertSame(
            [['publicUrl' => '/fileadmin/a.mp4', 'mimeType' => 'video/mp4', 'label' => 'Default', 'properties' => []]],
            $trackData['mediaFiles']
        );
    }

    #[Test]
    public function textTracksAreSplitIntoCaptionsAndChapters(): void
    {
        $captions = ['kind' => 'captions', 'src' => 'de.vtt'];
        $chapters = ['kind' => 'chapters', 'src' => 'chapters.vtt'];
        $track = ['src' => 'a.mp4', 'type' => 'video/mp4', 'tracks' => [$captions, $chapters]];

        $trackData = $this->subject->extract(self::trackResult([$track]))['trackData'];

        self::assertSame([$captions], $trackData['captions']);
        self::assertSame([$chapters], $trackData['chapters']);
    }

    #[Test]
    public function unsafePosterIsDropped(): void
    {
        $track = ['src' => 'a.mp4', 'type' => 'video/mp4', 'poster' => "javascript:alert(1)')"];

        $result = $this->subject->extract(self::trackResult([$track]));

        self::assertNull($result['trackData']['poster']);
        self::assertArrayNotHasKey('poster', $result['optionOverrides']);
    }

    #[Test]
    public function safePosterAndDurationBecomeOptions(): void
    {
        $track = ['src' => 'a.mp4', 'type' => 'video/mp4', 'poster' => '/fileadmin/poster.jpg', 'duration' => '125'];

        $result = $this->subject->extract(self::trackResult([$track]));

        self::assertSame('/fileadmin/poster.jpg', $result['trackData']['poster']);
        self::assertSame('/fileadmin/poster.jpg', $result['optionOverrides']['poster']);
        self::assertSame(125, $result['optionOverrides']['initialDuration']);
    }

    #[Test]
    public function audioDescriptionUsesTheSiteDefaultLanguage(): void
    {
        $track = ['src' => 'a.mp4', 'type' => 'video/mp4', 'audioDescriptionSrc' => '/fileadmin/ad.mp4'];

        $result = $this->subject->extract(self::trackResult([$track]), 'de');

        self::assertSame(
            [['src' => '/fileadmin/ad.mp4', 'lang' => 'de', 'label' => 'Audio Description', 'mimeType' => '']],
            $result['trackData']['audioDescriptionTracks']
        );
        self::assertSame('/fileadmin/ad.mp4', $result['optionOverrides']['audioDescriptionSrc']);
        self::assertTrue($result['optionOverrides']['audioDescriptionButton']);
        self::assertSame('auto', $result['optionOverrides']['audioDescriptionMode']);
    }

    #[Test]
    public function descriptionsTrackEnablesTheAudioDescriptionButton(): void
    {
        $track = ['src' => 'a.mp4', 'type' => 'video/mp4', 'tracks' => [['kind' => 'descriptions', 'src' => 'd.vtt']]];

        $overrides = $this->subject->extract(self::trackResult([$track]))['optionOverrides'];

        self::assertTrue($overrides['audioDescriptionButton']);
        self::assertArrayNotHasKey('audioDescriptionSrc', $overrides);
    }

    #[Test]
    public function unknownAudioDescriptionModeIsNotPassedOn(): void
    {
        $track = ['src' => 'a.mp4', 'type' => 'video/mp4', 'audioDescriptionMode' => 'bogus'];

        $overrides = $this->subject->extract(self::trackResult([$track]))['optionOverrides'];

        self::assertArrayNotHasKey('audioDescriptionMode', $overrides);
        self::assertArrayNotHasKey('audioDescriptionButton', $overrides);
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function signLanguageDisplayModeProvider(): array
    {
        return [
            'default' => [null, 'pip'],
            'main' => ['main', 'main'],
            'both' => ['both', 'both'],
            'invalid falls back to pip' => ['fullscreen', 'pip'],
        ];
    }

    #[Test]
    #[DataProvider('signLanguageDisplayModeProvider')]
    public function signLanguageEnablesTheButtonWithAValidDisplayMode(?string $displayMode, string $expected): void
    {
        $track = ['src' => 'a.mp4', 'type' => 'video/mp4', 'signLanguageSrc' => '/fileadmin/sl.mp4'];
        if ($displayMode !== null) {
            $track['signLanguageDisplayMode'] = $displayMode;
        }

        $result = $this->subject->extract(self::trackResult([$track]), 'de');

        self::assertSame(
            [['src' => '/fileadmin/sl.mp4', 'lang' => 'de', 'label' => 'Sign Language']],
            $result['trackData']['signLanguage']
        );
        self::assertTrue($result['optionOverrides']['signLanguageButton']);
        self::assertSame('bottom-right', $result['optionOverrides']['signLanguagePosition']);
        self::assertSame($expected, $result['optionOverrides']['signLanguageDisplayMode']);
    }

    #[Test]
    public function downloadIsOfferedOnlyWhenAllowed(): void
    {
        $track = ['src' => '/fileadmin/a.mp4', 'type' => 'video/mp4'];

        $denied = $this->subject->extract(self::trackResult([$track]));
        $allowed = $this->subject->extract(self::trackResult([$track + ['allowDownload' => true]]));

        self::assertArrayNotHasKey('downloadUrl', $denied['trackData']);
        self::assertArrayNotHasKey('downloadButton', $denied['optionOverrides']);
        self::assertSame('/fileadmin/a.mp4', $allowed['trackData']['downloadUrl']);
        self::assertTrue($allowed['optionOverrides']['downloadButton']);
    }

    #[Test]
    public function nonEmptyMetadataBecomesOptions(): void
    {
        $track = [
            'src' => 'a.mp4',
            'type' => 'video/mp4',
            'title' => 'Episode 1',
            'artist' => '   ',
            'description' => 'Short',
            'date' => 42,
        ];

        $overrides = $this->subject->extract(self::trackResult([$track]))['optionOverrides'];

        self::assertSame('Episode 1', $overrides['title']);
        self::assertSame('Short', $overrides['description']);
        self::assertArrayNotHasKey('artist', $overrides);
        self::assertArrayNotHasKey('date', $overrides);
        self::assertArrayNotHasKey('longDescription', $overrides);
    }
}
