<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service\Player;

use Mpc\MpcVidply\Service\Player\DownloadResolver;
use Mpc\MpcVidply\Service\Player\LocaleFormatter;
use Mpc\MpcVidply\Service\Player\MediaFileRegistry;
use Mpc\MpcVidply\Service\Player\TrackAssembler;
use Mpc\MpcVidply\Service\Player\UrlSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Only the record → track mapping is exercised here; resolving real sources
 * requires FAL and is covered by the functional suite.
 */
final class TrackAssemblerTest extends TestCase
{
    private TrackAssembler $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $files = (new \ReflectionClass(MediaFileRegistry::class))->newInstanceWithoutConstructor();

        $this->subject = new TrackAssembler(
            $files,
            new UrlSanitizer(),
            new DownloadResolver($files, new LocaleFormatter()),
            new LocaleFormatter()
        );
    }

    #[Test]
    public function buildBaseTrackDataMapsHideHelpButtonFlag(): void
    {
        $track = $this->subject->buildBaseTrackData([
            'title' => 'Example',
            'hide_help_button' => 1,
        ]);

        self::assertTrue($track['hideHelpButton']);
    }

    #[Test]
    public function buildBaseTrackDataAddsFormattedDateAndEpisodeNumber(): void
    {
        $track = $this->subject->buildBaseTrackData([
            'title' => 'Episode 11',
            'publish_date' => 1621296000,
            'episode_number' => ' 11 ',
        ], 'en-US');

        self::assertSame('11', $track['episodeNumber']);
        self::assertNotSame('', $track['date']);
        self::assertStringContainsString('2021', $track['date']);
    }

    #[Test]
    public function buildBaseTrackDataOmitsEmptyDateAndEpisodeNumber(): void
    {
        $track = $this->subject->buildBaseTrackData([
            'title' => 'Episode 11',
            'publish_date' => 0,
            'episode_number' => '   ',
        ]);

        self::assertArrayNotHasKey('date', $track);
        self::assertArrayNotHasKey('episodeNumber', $track);
    }

    #[Test]
    public function buildTrackSkipsRecordsWithAnUnknownMediaType(): void
    {
        self::assertNull($this->subject->buildTrack(['uid' => 7, 'media_type' => 'not-a-real-type']));
    }

    #[Test]
    public function assembleStructuredDataTrackSkipsUnsavedRecords(): void
    {
        self::assertNull($this->subject->assembleStructuredDataTrack(['uid' => 0, 'media_type' => 'video']));
    }
}
