<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Player;

use Mpc\MpcVidply\Service\DurationFormatter;
use Mpc\MpcVidply\Service\MediaCategoryResolver;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Server-rendered episode metadata for the "card" and "episodes" layouts.
 *
 * Everything the player itself renders is built by JavaScript from the playlist
 * JSON and would not be in the HTML source, so the layouts that print titles,
 * dates and covers get them from here instead.
 *
 * @phpstan-import-type TrackResult from TrackAssembler
 */
final class EpisodeListBuilder
{
    /** Presentation layouts of the player content element (tt_content.tx_mpcvidply_layout). */
    private const LAYOUTS = ['default', 'card', 'episodes'];

    /** Display orders of the episode list (tt_content.tx_mpcvidply_episode_sort). */
    private const EPISODE_SORTS = ['sorting', 'date_desc', 'date_asc', 'title_asc'];

    private readonly MediaFileRegistry $files;
    private readonly MediaCategoryResolver $mediaCategoryResolver;
    private readonly DurationFormatter $durationFormatter;
    private readonly DownloadResolver $downloadResolver;
    private readonly LocaleFormatter $localeFormatter;

    public function __construct(
        ?MediaFileRegistry $files = null,
        ?MediaCategoryResolver $mediaCategoryResolver = null,
        ?DurationFormatter $durationFormatter = null,
        ?DownloadResolver $downloadResolver = null,
        ?LocaleFormatter $localeFormatter = null
    ) {
        $this->files = $files ?? GeneralUtility::makeInstance(MediaFileRegistry::class);
        $this->mediaCategoryResolver = $mediaCategoryResolver
            ?? GeneralUtility::makeInstance(MediaCategoryResolver::class);
        $this->durationFormatter = $durationFormatter ?? GeneralUtility::makeInstance(DurationFormatter::class);
        $this->downloadResolver = $downloadResolver ?? GeneralUtility::makeInstance(DownloadResolver::class);
        $this->localeFormatter = $localeFormatter ?? GeneralUtility::makeInstance(LocaleFormatter::class);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function resolveLayout(array $data): string
    {
        $layout = trim((string)($data['tx_mpcvidply_layout'] ?? ''));

        return in_array($layout, self::LAYOUTS, true) ? $layout : 'default';
    }

    /**
     * Preselected order of the episode list. Only the display order changes:
     * every episode keeps its `index`, which is the playlist track index the
     * player plays by.
     *
     * @param array<string, mixed> $data
     */
    public function resolveSort(array $data): string
    {
        $sort = trim((string)($data['tx_mpcvidply_episode_sort'] ?? ''));

        return in_array($sort, self::EPISODE_SORTS, true) ? $sort : 'sorting';
    }

    /**
     * Episode metadata for the layouts that print it. "episodes" lists every
     * record, "card" only ever shows the first one — so the poster and category
     * lookups of the remaining records are skipped there.
     *
     * @param TrackResult $trackResult
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public function build(string $layout, array $trackResult, int $languageId, array $data, ?string $locale = null): array
    {
        $records = $trackResult['records'];
        if ($layout === 'default' || $records === []) {
            return [];
        }

        $tracks = $trackResult['tracks'];
        if ($layout === 'card') {
            $records = array_slice($records, 0, 1);
            $tracks = array_slice($tracks, 0, 1);
        }

        // Only the episode list offers downloads per row: every episode is
        // visible there, so each one can be saved without selecting it first.
        // The card layout shows a single episode and lets the player's own
        // button — which follows the selected track — handle downloads instead.
        $downloadTracks = $trackResult['isPlaylist'] && $layout === 'episodes' ? $tracks : [];

        $episodes = $this->buildEpisodeData(
            $records,
            $this->resolveEpisodeCategories($records, $languageId),
            $downloadTracks,
            $locale
        );

        return $layout === 'episodes'
            ? $this->sortEpisodes($episodes, $this->resolveSort($data))
            : $episodes;
    }

    /**
     * The episode the player starts on, i.e. the one with playlist track index 0.
     *
     * @param list<array<string, mixed>> $episodes
     * @return array<string, mixed>|null
     */
    public function resolveLeadEpisode(array $episodes): ?array
    {
        foreach ($episodes as $episode) {
            if ((int)($episode['index'] ?? 0) === 0) {
                return $episode;
            }
        }

        return $episodes[0] ?? null;
    }

    /**
     * Sort and pagination configuration of the episode list. Pagination only
     * becomes active once there are more episodes than fit on a page.
     *
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $episodes
     * @return array{
     *     episodeSort: string,
     *     paginationEnabled: bool,
     *     paginationPerPage: int,
     *     paginationActive: bool
     * }
     */
    public function resolveListSettings(array $data, string $layout, array $episodes): array
    {
        $perPage = max(1, min(200, (int)($data['tx_mpcvidply_episode_per_page'] ?? 10)));
        $enabled = $layout === 'episodes' && (int)($data['tx_mpcvidply_episode_pagination'] ?? 1) === 1;

        return [
            'episodeSort' => $this->resolveSort($data),
            'paginationEnabled' => $enabled,
            'paginationPerPage' => $perPage,
            'paginationActive' => $enabled && count($episodes) > $perPage,
        ];
    }

    /**
     * @param list<array<string, mixed>> $mediaRecords
     * @param list<list<array{uid: int, title: string}>> $categories Aligned with $mediaRecords
     * @param list<array<string, mixed>> $downloadTracks Tracks whose download URL should be
     *        printed per episode, aligned with $mediaRecords. Empty when the player renders
     *        its own download button.
     * @return list<array<string, mixed>>
     */
    private function buildEpisodeData(array $mediaRecords, array $categories, array $downloadTracks, ?string $locale): array
    {
        $episodes = [];

        foreach (array_values($mediaRecords) as $index => $mediaRecord) {
            if (!is_array($mediaRecord)) {
                continue;
            }

            $mediaUid = (int)($mediaRecord['uid'] ?? 0);
            $title = (string)($mediaRecord['title'] ?? '');
            [$posterReferenceUid, $posterAlt] = $this->resolveEpisodePoster($mediaUid, $title);
            $duration = (int)($mediaRecord['duration'] ?? 0);
            $publishDate = (int)($mediaRecord['publish_date'] ?? 0);
            $download = $this->downloadResolver->describeForEpisode($mediaRecord, $downloadTracks[$index] ?? null, $locale);

            $episodes[] = [
                'index' => $index,
                'uid' => $mediaUid,
                'title' => $title,
                'artist' => (string)($mediaRecord['artist'] ?? ''),
                'episodeNumber' => trim((string)($mediaRecord['episode_number'] ?? '')),
                'dateFormatted' => $this->localeFormatter->formatDate($publishDate, $locale),
                'dateIso' => $this->localeFormatter->formatIsoDate($publishDate),
                'duration' => $duration,
                'durationFormatted' => $this->durationFormatter->format($duration),
                'description' => (string)($mediaRecord['description'] ?? ''),
                'longDescription' => (string)($mediaRecord['long_description'] ?? ''),
                'categories' => $categories[$index] ?? [],
                'posterReferenceUid' => $posterReferenceUid,
                'posterAlt' => $posterAlt,
                'downloadUrl' => $download['url'],
                'downloadInfo' => $download['info'],
            ];
        }

        return $episodes;
    }

    /**
     * Episodes without a publish date sort last in both date modes, so an
     * incomplete record never pushes itself to the top of a "newest first" list.
     *
     * @param list<array<string, mixed>> $episodes
     * @return list<array<string, mixed>>
     */
    private function sortEpisodes(array $episodes, string $sort): array
    {
        if ($sort === 'sorting' || count($episodes) < 2) {
            return $episodes;
        }

        usort($episodes, static function (array $a, array $b) use ($sort): int {
            if ($sort === 'title_asc') {
                $comparison = strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
            } else {
                $dateA = (string)($a['dateIso'] ?? '');
                $dateB = (string)($b['dateIso'] ?? '');

                if ($dateA === '' || $dateB === '') {
                    $comparison = $dateA === $dateB ? 0 : ($dateA === '' ? 1 : -1);
                } else {
                    $comparison = strcmp($dateA, $dateB);
                    if ($sort === 'date_desc') {
                        $comparison = -$comparison;
                    }
                }
            }

            return $comparison !== 0
                ? $comparison
                : (int)($a['index'] ?? 0) <=> (int)($b['index'] ?? 0);
        });

        return $episodes;
    }

    /**
     * Categories of every media record, in the order of $mediaRecords.
     *
     * @param list<array<string, mixed>> $mediaRecords
     * @return list<list<array{uid: int, title: string}>>
     */
    private function resolveEpisodeCategories(array $mediaRecords, int $languageId): array
    {
        $categoryMap = $this->mediaCategoryResolver->fetchForMediaRecords($mediaRecords, $languageId);

        $categories = [];
        foreach (array_values($mediaRecords) as $mediaRecord) {
            $categories[] = is_array($mediaRecord)
                ? $this->mediaCategoryResolver->resolveForMedia($mediaRecord, $categoryMap)
                : [];
        }

        return $categories;
    }

    /**
     * @return array{0: ?int, 1: string}
     */
    private function resolveEpisodePoster(int $mediaUid, string $title): array
    {
        $posterRefs = $this->files->getReferences($mediaUid, 'poster');
        if ($posterRefs === []) {
            return [null, $title];
        }

        $referenceUid = (int)$posterRefs[0]->getUid();
        if ($referenceUid <= 0) {
            return [null, $title];
        }

        $alternative = trim((string)$posterRefs[0]->getAlternative());

        return [$referenceUid, $alternative !== '' ? $alternative : $title];
    }
}
