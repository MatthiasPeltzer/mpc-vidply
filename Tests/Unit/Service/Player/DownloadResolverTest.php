<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service\Player;

use Mpc\MpcVidply\Service\Player\DownloadResolver;
use Mpc\MpcVidply\Service\Player\LocaleFormatter;
use Mpc\MpcVidply\Service\Player\MediaFileRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Records without a uid have no measurable file behind them, which keeps the
 * subject away from FAL: only the source selection and the format label are
 * exercised here, the byte size is covered by the functional suite.
 */
final class DownloadResolverTest extends TestCase
{
    private DownloadResolver $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new DownloadResolver(
            (new \ReflectionClass(MediaFileRegistry::class))->newInstanceWithoutConstructor(),
            new LocaleFormatter()
        );
    }

    #[Test]
    public function enrichTrackOffersTheFileWithItsFormat(): void
    {
        $track = ['allowDownload' => true, 'src' => '/fileadmin/a.mp3', 'type' => 'audio/mpeg'];

        $this->subject->enrichTrack($track, 0);

        self::assertSame('/fileadmin/a.mp3', $track['downloadUrl']);
        self::assertSame('MP3', $track['downloadFormat']);
        self::assertArrayNotHasKey('downloadFileSize', $track);
    }

    #[Test]
    public function enrichTrackPrefersAProgressiveSourceOverAManifest(): void
    {
        $track = $this->manifestWithProgressiveFallback();
        $track['allowDownload'] = true;

        $this->subject->enrichTrack($track, 0);

        self::assertSame('https://cdn.example.com/a.mp4', $track['downloadUrl']);
        self::assertSame('MP4', $track['downloadFormat']);
    }

    #[Test]
    public function enrichTrackStaysEmptyWithoutTheRecordsPermission(): void
    {
        $track = ['src' => '/fileadmin/a.mp3', 'type' => 'audio/mpeg'];

        $this->subject->enrichTrack($track, 0);

        self::assertArrayNotHasKey('downloadUrl', $track);
    }

    #[Test]
    public function resolveUrlFallsBackToTheTrackSource(): void
    {
        self::assertSame(
            '/fileadmin/a.mp3',
            $this->subject->resolveUrl(['src' => '/fileadmin/a.mp3', 'type' => 'audio/mpeg'])
        );
        self::assertNull($this->subject->resolveUrl(['type' => 'audio/mpeg']));
    }

    #[Test]
    public function describeForEpisodePrefersAProgressiveSourceOverAManifest(): void
    {
        $download = $this->subject->describeForEpisode(
            ['uid' => 0, 'allow_download' => 1],
            $this->manifestWithProgressiveFallback(),
            'en-US'
        );

        self::assertSame('https://cdn.example.com/a.mp4', $download['url']);
        self::assertSame('MP4', $download['info']);
    }

    #[Test]
    public function describeForEpisodeStaysEmptyWithoutTheRecordsPermission(): void
    {
        $download = $this->subject->describeForEpisode(
            ['uid' => 0],
            ['src' => '/fileadmin/a.mp3', 'type' => 'audio/mpeg'],
            'en-US'
        );

        self::assertSame(['url' => '', 'info' => ''], $download);
    }

    #[Test]
    public function describeForEpisodeStaysEmptyWithoutATrack(): void
    {
        self::assertSame(
            ['url' => '', 'info' => ''],
            $this->subject->describeForEpisode(['uid' => 7, 'allow_download' => 1], null, null)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestWithProgressiveFallback(): array
    {
        return [
            'src' => 'https://cdn.example.com/a.m3u8',
            'type' => 'application/x-mpegurl',
            'sources' => [
                ['src' => 'https://cdn.example.com/a.m3u8', 'type' => 'application/x-mpegurl'],
                ['src' => 'https://cdn.example.com/a.mp4', 'type' => 'video/mp4'],
            ],
        ];
    }
}
