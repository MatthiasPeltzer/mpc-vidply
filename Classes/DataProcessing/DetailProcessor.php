<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\DataProcessing;

use Mpc\MpcVidply\Repository\MediaRepository;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

/**
 * DataProcessor for the VidPly Detail content element.
 *
 * Reads the `media` query parameter (set by the Simple route enhancer from the slug)
 * and prepares the full template data: player markup, metadata, JSON-LD, and an optional
 * "next in category" row of related media.
 */
final class DetailProcessor implements DataProcessorInterface
{
    private readonly MediaRepository $mediaRepository;
    private readonly VidPlyProcessor $vidPlyProcessor;
    private readonly ConnectionPool $connectionPool;
    private readonly ResourceFactory $resourceFactory;

    public function __construct(
        ?MediaRepository $mediaRepository = null,
        ?VidPlyProcessor $vidPlyProcessor = null,
        ?ConnectionPool $connectionPool = null,
        ?ResourceFactory $resourceFactory = null
    ) {
        $this->mediaRepository = $mediaRepository ?? GeneralUtility::makeInstance(MediaRepository::class);
        $this->vidPlyProcessor = $vidPlyProcessor ?? GeneralUtility::makeInstance(VidPlyProcessor::class);
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->resourceFactory = $resourceFactory ?? GeneralUtility::makeInstance(ResourceFactory::class);
    }

    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ): array {
        $data = $processedData['data'];
        $request = $cObj->getRequest();
        $languageId = $this->resolveLanguageId($request, $data);

        $media = $this->resolveMediaRecord($request, $languageId);

        if ($media === null) {
            $processedData['detail'] = [
                'found' => false,
                'title' => '',
                'description' => '',
                'longDescription' => '',
                'artist' => '',
                'duration' => 0,
                'durationFormatted' => '',
                'categories' => [],
                'jsonLd' => null,
                'related' => [],
            ];
            $processedData['vidply'] = null;
            return $processedData;
        }

        // Delegate the full player assembly to the existing VidPlyProcessor so we inherit
        // every capability (privacy layer, playlist detection, HLS/DASH, etc.) without duplication.
        $processedData['vidply'] = $this->vidPlyProcessor->assembleForMediaRecords(
            [$media],
            $data,
            $request,
            $languageId
        );

        $detail = $this->assembleDetailData($media, $languageId);
        $showRelated = (int)($data['tx_mpcvidply_show_related'] ?? 1) === 1;
        if ($showRelated) {
            $relatedRaw = $this->mediaRepository->findNextInCategory((int)$media['uid'], $languageId, 6);
            $detail['related'] = $this->buildRelatedCards($relatedRaw, $cObj, (int)($data['pid'] ?? 0), $languageId);
        } else {
            $detail['related'] = [];
        }

        $processedData['detail'] = $detail;

        return $processedData;
    }

    private function resolveMediaRecord(ServerRequestInterface $request, int $languageId): ?array
    {
        $queryParams = $request->getQueryParams();
        $mediaParam = $queryParams['media'] ?? null;

        if (is_numeric($mediaParam)) {
            return $this->mediaRepository->findByUid((int)$mediaParam, $languageId);
        }
        if (is_string($mediaParam) && trim($mediaParam) !== '') {
            return $this->mediaRepository->findBySlug(trim($mediaParam), $languageId);
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function assembleDetailData(array $media, int $languageId): array
    {
        $mediaUid = (int)($media['uid'] ?? 0);
        $title = (string)($media['title'] ?? '');
        $description = (string)($media['description'] ?? '');
        $longDescription = (string)($media['long_description'] ?? '');
        $artist = (string)($media['artist'] ?? '');
        $duration = (int)($media['duration'] ?? 0);
        $mediaType = (string)($media['media_type'] ?? 'video');

        $poster = $this->resolvePosterFile($mediaUid);
        $posterUrl = $poster['url'] ?? null;

        $mediaUidForRel = (int)($media['l10n_parent'] ?? 0) > 0
            ? (int)($media['l10n_parent'] ?? 0)
            : $mediaUid;
        $categories = $this->fetchCategoriesForMedia($mediaUidForRel, $languageId);
        if ($categories === [] && (int)($media['l10n_parent'] ?? 0) > 0) {
            $categories = $this->fetchCategoriesForMedia($mediaUid, $languageId);
        }

        $jsonLd = $this->buildJsonLd(
            $title,
            $description,
            $duration,
            $posterUrl,
            $mediaType,
            (int)($media['crdate'] ?? 0)
        );

        return [
            'found' => true,
            'mediaUid' => $mediaUid,
            'slug' => (string)($media['slug'] ?? ''),
            'title' => $title,
            'description' => $description,
            'longDescription' => $longDescription,
            'artist' => $artist,
            'duration' => $duration,
            'durationFormatted' => $this->formatDuration($duration),
            'mediaType' => $mediaType,
            'poster' => $posterUrl,
            'ogImage' => $posterUrl,
            'categories' => $categories,
            'jsonLd' => $jsonLd,
        ];
    }

    /**
     * @param list<array<string, mixed>> $relatedRaw
     * @return list<array<string, mixed>>
     */
    private function buildRelatedCards(array $relatedRaw, ContentObjectRenderer $cObj, int $currentPid, int $languageId): array
    {
        if ($relatedRaw === []) {
            return [];
        }

        $mediaUids = array_values(array_filter(
            array_map(static fn(array $m): int => (int)($m['uid'] ?? 0), $relatedRaw),
            static fn(int $uid): bool => $uid > 0
        ));
        $posterRefsByMediaUid = $this->prefetchPosterRefs($mediaUids);

        $cards = [];
        foreach ($relatedRaw as $media) {
            $uid = (int)($media['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $defaultUid = (int)($media['l10n_parent'] ?? 0);
            if ($defaultUid <= 0) {
                $defaultUid = $uid;
            }
            $posterRefs = $posterRefsByMediaUid[$uid] ?? $posterRefsByMediaUid[$defaultUid] ?? [];
            $posterUrl = null;
            $posterReferenceUid = null;
            $posterAlt = (string)($media['title'] ?? '');
            if ($posterRefs !== []) {
                $reference = $posterRefs[0];
                $refUid = (int)$reference->getUid();
                if ($refUid > 0) {
                    $posterReferenceUid = $refUid;
                }
                $url = (string)$reference->getPublicUrl();
                $posterUrl = $url !== '' ? $url : null;
                $alt = trim((string)$reference->getAlternative());
                if ($alt !== '') {
                    $posterAlt = $alt;
                }
            }

            $slug = trim((string)($media['slug'] ?? ''));
            $duration = (int)($media['duration'] ?? 0);

            $cards[] = [
                'uid' => $uid,
                'title' => (string)($media['title'] ?? ''),
                'artist' => (string)($media['artist'] ?? ''),
                'duration' => $duration,
                'durationFormatted' => $this->formatDuration($duration),
                'slug' => $slug,
                'mediaType' => (string)($media['media_type'] ?? 'video'),
                'posterReferenceUid' => $posterReferenceUid,
                'poster' => $posterUrl,
                'posterAlt' => $posterAlt,
                'detailUrl' => $this->buildDetailUrl($cObj, $currentPid, $defaultUid, $slug, $languageId),
            ];
        }
        return $cards;
    }

    /**
     * Build the detail-page URL for one related media record.
     *
     * Mirrors {@see ListviewProcessor::buildDetailUrl()}: typoLink is asked to
     * link the current detail page with `media=<uid>`; the route enhancer
     * rewrites this to a slug path when a slug exists and falls back to the
     * id-style `?media=<uid>&cHash=…` form otherwise.
     */
    private function buildDetailUrl(
        ContentObjectRenderer $cObj,
        int $pageUid,
        int $mediaUid,
        string $slug,
        int $languageId
    ): string {
        if ($pageUid <= 0 || $mediaUid <= 0) {
            return '';
        }
        $config = [
            'parameter' => $pageUid,
            'queryParameters' => [
                'media' => (string)$mediaUid,
            ],
            'returnLast' => 'url',
        ];
        if ($languageId > 0) {
            $config['language'] = $languageId;
        }
        try {
            $url = (string)$cObj->typoLink_URL($config);
        } catch (\Throwable) {
            $url = '';
        }
        if ($url === '') {
            if ($slug !== '') {
                return '/' . ltrim($slug, '/');
            }
            return '?media=' . $mediaUid;
        }
        return $url;
    }

    /**
     * @return array{url: ?string}
     */
    private function resolvePosterFile(int $mediaUid): array
    {
        if ($mediaUid <= 0) {
            return ['url' => null];
        }
        $refs = $this->prefetchPosterRefs([$mediaUid])[$mediaUid] ?? [];
        if ($refs === []) {
            return ['url' => null];
        }
        $url = (string)$refs[0]->getPublicUrl();
        return ['url' => $url !== '' ? $url : null];
    }

    /**
     * @param list<int> $mediaUids
     * @return array<int, FileReference[]>
     */
    private function prefetchPosterRefs(array $mediaUids): array
    {
        if ($mediaUids === []) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $rows = $qb
            ->select('uid', 'uid_foreign')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tx_mpcvidply_media')),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter('poster')),
                $qb->expr()->in(
                    'uid_foreign',
                    $qb->createNamedParameter($mediaUids, Connection::PARAM_INT_ARRAY)
                ),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('uid_foreign', 'ASC')
            ->addOrderBy('sorting_foreign', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $fileRefUid = (int)($row['uid'] ?? 0);
            $mediaUid = (int)($row['uid_foreign'] ?? 0);
            if ($fileRefUid <= 0 || $mediaUid <= 0) {
                continue;
            }
            try {
                $ref = $this->resourceFactory->getFileReferenceObject($fileRefUid);
            } catch (ResourceDoesNotExistException) {
                continue;
            }
            $result[$mediaUid] ??= [];
            $result[$mediaUid][] = $ref;
        }
        return $result;
    }

    /**
     * @return list<array{uid:int,title:string}>
     */
    private function fetchCategoriesForMedia(int $mediaUid, int $languageId): array
    {
        if ($mediaUid <= 0) {
            return [];
        }
        $lang = array_values(array_unique([0, -1, $languageId], SORT_REGULAR));
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_category');
        $rows = $qb
            ->select('sys_category.uid', 'sys_category.title')
            ->from('sys_category')
            ->join(
                'sys_category',
                'sys_category_record_mm',
                'mm',
                (string)$qb->expr()->and(
                    $qb->expr()->eq('mm.uid_local', $qb->quoteIdentifier('sys_category.uid')),
                    $qb->expr()->eq('mm.tablenames', $qb->createNamedParameter('tx_mpcvidply_media')),
                    $qb->expr()->eq('mm.fieldname', $qb->createNamedParameter('categories')),
                    $qb->expr()->eq('mm.uid_foreign', $qb->createNamedParameter($mediaUid, Connection::PARAM_INT))
                )
            )
            ->where(
                $qb->expr()->eq('sys_category.deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('sys_category.hidden', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->in(
                    'sys_category.sys_language_uid',
                    $qb->createNamedParameter($lang, Connection::PARAM_INT_ARRAY)
                )
            )
            ->groupBy('sys_category.uid', 'sys_category.title')
            ->orderBy('mm.sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $r): array => [
                'uid' => (int)($r['uid'] ?? 0),
                'title' => (string)($r['title'] ?? ''),
            ],
            $rows
        );
    }

    /**
     * Build a schema.org VideoObject / AudioObject JSON-LD blob.
     * Returns the already-encoded JSON string so the template can embed it with <f:format.raw>.
     */
    private function buildJsonLd(
        string $title,
        string $description,
        int $duration,
        ?string $posterUrl,
        string $mediaType,
        int $uploadTimestamp
    ): ?string {
        if ($title === '') {
            return null;
        }

        $isAudio = in_array($mediaType, ['audio', 'soundcloud'], true);
        $data = [
            '@context' => 'https://schema.org',
            '@type' => $isAudio ? 'AudioObject' : 'VideoObject',
            'name' => $title,
        ];
        if ($description !== '') {
            $data['description'] = strip_tags($description);
        }
        if ($duration > 0) {
            $data['duration'] = $this->toIsoDuration($duration);
        }
        if ($posterUrl !== null && $posterUrl !== '') {
            $data['thumbnailUrl'] = $posterUrl;
        }
        if ($uploadTimestamp > 0) {
            $data['uploadDate'] = gmdate('Y-m-d\TH:i:s\Z', $uploadTimestamp);
        }

        try {
            return json_encode(
                $data,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function toIsoDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'PT0S';
        }
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        $iso = 'PT';
        if ($hours > 0) {
            $iso .= $hours . 'H';
        }
        if ($minutes > 0) {
            $iso .= $minutes . 'M';
        }
        if ($secs > 0 || ($hours === 0 && $minutes === 0)) {
            $iso .= $secs . 'S';
        }
        return $iso;
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '';
        }
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }
        return sprintf('%d:%02d', $minutes, $secs);
    }

    private function resolveLanguageId(ServerRequestInterface $request, array $data): int
    {
        $language = $request->getAttribute('language');
        if ($language !== null) {
            $id = (int)($language->toArray()['languageId'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }
        return (int)($data['sys_language_uid'] ?? 0);
    }
}
