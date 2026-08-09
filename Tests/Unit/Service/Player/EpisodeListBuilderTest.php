<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service\Player;

use Mpc\MpcVidply\Service\DurationFormatter;
use Mpc\MpcVidply\Service\MediaCategoryResolver;
use Mpc\MpcVidply\Service\Player\DownloadResolver;
use Mpc\MpcVidply\Service\Player\EpisodeListBuilder;
use Mpc\MpcVidply\Service\Player\LocaleFormatter;
use Mpc\MpcVidply\Service\Player\MediaFileRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The fixtures below use records without a uid, so poster and category lookups
 * resolve to nothing and the builder stays away from the database services.
 * Their unconstructed instances are enough to satisfy the constructor.
 */
final class EpisodeListBuilderTest extends TestCase
{
    private EpisodeListBuilder $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $files = (new \ReflectionClass(MediaFileRegistry::class))->newInstanceWithoutConstructor();

        $this->subject = new EpisodeListBuilder(
            $files,
            (new \ReflectionClass(MediaCategoryResolver::class))->newInstanceWithoutConstructor(),
            new DurationFormatter(),
            new DownloadResolver($files, new LocaleFormatter()),
            new LocaleFormatter()
        );
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(EpisodeListBuilder::class, $method))->invoke($this->subject, ...$args);
    }

    #[Test]
    #[DataProvider('layoutProvider')]
    public function resolveLayoutFallsBackToDefaultForUnknownValues(mixed $stored, string $expected): void
    {
        self::assertSame($expected, $this->subject->resolveLayout(['tx_mpcvidply_layout' => $stored]));
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
        self::assertSame('default', $this->subject->resolveLayout([]));
    }

    #[Test]
    #[DataProvider('episodeSortProvider')]
    public function resolveSortFallsBackToManualOrder(mixed $stored, string $expected): void
    {
        self::assertSame($expected, $this->subject->resolveSort(['tx_mpcvidply_episode_sort' => $stored]));
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
    public function resolveSortDefaultsWhenFieldIsMissing(): void
    {
        self::assertSame('sorting', $this->subject->resolveSort([]));
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

        $lead = $this->subject->resolveLeadEpisode($episodes);

        self::assertIsArray($lead);
        self::assertSame(0, $lead['index']);
    }

    #[Test]
    public function resolveLeadEpisodeReturnsNullWithoutEpisodes(): void
    {
        self::assertNull($this->subject->resolveLeadEpisode([]));
    }

    #[Test]
    public function resolveListSettingsClampsThePerPageValue(): void
    {
        $settings = $this->subject->resolveListSettings(['tx_mpcvidply_episode_per_page' => 0], 'episodes', []);
        self::assertSame(1, $settings['paginationPerPage']);

        $settings = $this->subject->resolveListSettings(['tx_mpcvidply_episode_per_page' => 5000], 'episodes', []);
        self::assertSame(200, $settings['paginationPerPage']);

        $settings = $this->subject->resolveListSettings([], 'episodes', []);
        self::assertSame(10, $settings['paginationPerPage']);
    }

    #[Test]
    public function resolveListSettingsActivatesPaginationOnlyAboveThePageSize(): void
    {
        $data = ['tx_mpcvidply_episode_pagination' => 1, 'tx_mpcvidply_episode_per_page' => 2];

        $settings = $this->subject->resolveListSettings($data, 'episodes', array_fill(0, 2, ['index' => 0]));
        self::assertTrue($settings['paginationEnabled']);
        self::assertFalse($settings['paginationActive']);

        $settings = $this->subject->resolveListSettings($data, 'episodes', array_fill(0, 3, ['index' => 0]));
        self::assertTrue($settings['paginationActive']);
    }

    #[Test]
    public function resolveListSettingsDisablesPaginationOutsideTheEpisodeList(): void
    {
        $data = ['tx_mpcvidply_episode_pagination' => 1, 'tx_mpcvidply_episode_per_page' => 1];

        $settings = $this->subject->resolveListSettings($data, 'card', array_fill(0, 5, ['index' => 0]));

        self::assertFalse($settings['paginationEnabled']);
        self::assertFalse($settings['paginationActive']);
    }

    #[Test]
    public function resolveListSettingsHonoursTheDisabledToggle(): void
    {
        $data = ['tx_mpcvidply_episode_pagination' => 0, 'tx_mpcvidply_episode_per_page' => 1];

        $settings = $this->subject->resolveListSettings($data, 'episodes', array_fill(0, 5, ['index' => 0]));

        self::assertFalse($settings['paginationEnabled']);
        self::assertFalse($settings['paginationActive']);
    }

    #[Test]
    public function buildEpisodeDataExposesServerRenderedMetadata(): void
    {
        $episodes = $this->invoke(
            'buildEpisodeData',
            [[
                'title' => 'Episode 11',
                'artist' => 'MP Core',
                'description' => 'Show notes',
                'duration' => 3725,
                'publish_date' => 1621296000,
                'episode_number' => '11',
            ]],
            [[['uid' => 3, 'title' => 'Politics']]],
            [],
            'en-US'
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
        $episodes = $this->invoke('buildEpisodeData', [['title' => 'Episode 12']], [], [], null);

        self::assertSame('', $episodes[0]['dateFormatted']);
        self::assertSame('', $episodes[0]['dateIso']);
        self::assertSame('', $episodes[0]['durationFormatted']);
        self::assertSame([], $episodes[0]['categories']);
    }

    #[Test]
    public function buildsNothingForTheDefaultLayout(): void
    {
        self::assertSame([], $this->subject->build('default', $this->episodesTrackResult(1), 0, []));
    }

    #[Test]
    public function onlyBuildsTheFirstRecordForTheCardLayout(): void
    {
        $episodes = $this->subject->build('card', $this->episodesTrackResult(3), 0, []);

        self::assertCount(1, $episodes);
        self::assertSame('A', $episodes[0]['title']);
    }

    #[Test]
    public function buildsEveryRecordForTheEpisodesLayout(): void
    {
        $episodes = $this->subject->build('episodes', $this->episodesTrackResult(3), 0, []);

        self::assertCount(3, $episodes);
        self::assertSame([0, 1, 2], array_column($episodes, 'index'));
        self::assertSame(['A', 'B', 'C'], array_column($episodes, 'title'));
    }

    #[Test]
    public function appliesTheConfiguredOrderToTheListOnly(): void
    {
        $trackResult = $this->episodesTrackResult(3);
        $trackResult['records'][0]['title'] = 'Zulu';

        $episodes = $this->subject->build(
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
    public function offersADownloadPerEpisodeForPlaylists(): void
    {
        $trackResult = $this->episodesTrackResult(2);
        $trackResult['records'][0]['allow_download'] = 1;
        $trackResult['tracks'][0] = ['src' => '/fileadmin/a.mp3', 'type' => 'audio/mpeg'];

        $episodes = $this->subject->build('episodes', $trackResult, 0, []);

        self::assertSame('/fileadmin/a.mp3', $episodes[0]['downloadUrl']);
        self::assertSame('MP3', $episodes[0]['downloadInfo']);
        self::assertSame('', $episodes[1]['downloadUrl']);
    }

    #[Test]
    public function leavesTheDownloadToThePlayerForASingleMedium(): void
    {
        $trackResult = $this->episodesTrackResult(1);
        $trackResult['isPlaylist'] = false;
        $trackResult['records'][0]['allow_download'] = 1;
        $trackResult['tracks'][0] = ['src' => '/fileadmin/a.mp3', 'type' => 'audio/mpeg'];

        $episodes = $this->subject->build('card', $trackResult, 0, []);

        self::assertSame('', $episodes[0]['downloadUrl']);
    }

    /**
     * The card shows one episode while the player may be on another, so a link
     * printed there would age. The player's button follows the track instead.
     */
    #[Test]
    public function leavesTheDownloadToThePlayerForTheCardLayout(): void
    {
        $trackResult = $this->episodesTrackResult(3);
        $trackResult['records'][0]['allow_download'] = 1;
        $trackResult['tracks'][0] = ['src' => '/fileadmin/a.mp3', 'type' => 'audio/mpeg'];

        $episodes = $this->subject->build('card', $trackResult, 0, []);

        self::assertSame('', $episodes[0]['downloadUrl']);
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
}
