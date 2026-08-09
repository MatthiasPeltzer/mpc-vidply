<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\DataProcessing;

use Mpc\MpcVidply\Repository\MediaRepository;
use Mpc\MpcVidply\Service\DetailMetaTagService;
use Mpc\MpcVidply\Service\DetailUrlBuilder;
use Mpc\MpcVidply\Service\DurationFormatter;
use Mpc\MpcVidply\Service\FileReferencePrefetcher;
use Mpc\MpcVidply\Service\FrontendLanguageResolver;
use Mpc\MpcVidply\Service\MediaCategoryResolver;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Resource\FileReference;
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
    private readonly MediaCategoryResolver $mediaCategoryResolver;
    private readonly DetailMetaTagService $metaTagService;
    private readonly FileReferencePrefetcher $fileReferencePrefetcher;
    private readonly DetailUrlBuilder $detailUrlBuilder;
    private readonly DurationFormatter $durationFormatter;

    public function __construct(
        ?MediaRepository $mediaRepository = null,
        ?VidPlyProcessor $vidPlyProcessor = null,
        ?MediaCategoryResolver $mediaCategoryResolver = null,
        ?DetailMetaTagService $metaTagService = null,
        ?FileReferencePrefetcher $fileReferencePrefetcher = null,
        ?DetailUrlBuilder $detailUrlBuilder = null,
        ?DurationFormatter $durationFormatter = null
    ) {
        $this->mediaRepository = $mediaRepository ?? GeneralUtility::makeInstance(MediaRepository::class);
        $this->vidPlyProcessor = $vidPlyProcessor ?? GeneralUtility::makeInstance(VidPlyProcessor::class);
        $this->mediaCategoryResolver = $mediaCategoryResolver ?? GeneralUtility::makeInstance(MediaCategoryResolver::class);
        $this->metaTagService = $metaTagService ?? GeneralUtility::makeInstance(DetailMetaTagService::class);
        $this->fileReferencePrefetcher = $fileReferencePrefetcher
            ?? GeneralUtility::makeInstance(FileReferencePrefetcher::class);
        $this->detailUrlBuilder = $detailUrlBuilder ?? GeneralUtility::makeInstance(DetailUrlBuilder::class);
        $this->durationFormatter = $durationFormatter ?? GeneralUtility::makeInstance(DurationFormatter::class);
    }

    /**
     * @param array<string, mixed> $contentObjectConfiguration
     * @param array<string, mixed> $processorConfiguration
     * @param array<string, mixed> $processedData
     * @return array<string, mixed>
     */
    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ): array {
        $data = $processedData['data'];
        $request = $cObj->getRequest();
        $languageId = FrontendLanguageResolver::resolveLanguageId($request, $data);

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
                'related' => [],
            ];
            $processedData['vidply'] = null;
            return $processedData;
        }

        // Override description + OpenGraph/Twitter meta tags with the media
        // element's own title/description/poster (the HTML <title> is handled by
        // VidPlyDetailPageTitleProvider). Runs before EXT:seo's meta tag hook.
        $this->metaTagService->applyForMedia($media, $this->resolvePosterReference((int)($media['uid'] ?? 0)));

        // Delegate the full player assembly to the existing VidPlyProcessor so we inherit
        // every capability (privacy layer, playlist detection, HLS/DASH, etc.) without duplication.
        $vidply = $this->vidPlyProcessor->assembleForMediaRecords(
            [$media],
            $data,
            $request,
            $languageId
        );
        $processedData['vidply'] = $vidply;

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

    /**
     * @return array<string, mixed>|null
     */
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
     * @param array<string, mixed> $media
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

        $categories = $this->mediaCategoryResolver->fetchForMedia($media, $languageId);

        return [
            'found' => true,
            'mediaUid' => $mediaUid,
            'slug' => (string)($media['slug'] ?? ''),
            'title' => $title,
            'description' => $description,
            'longDescription' => $longDescription,
            'artist' => $artist,
            'duration' => $duration,
            'durationFormatted' => $this->durationFormatter->format($duration),
            'mediaType' => $mediaType,
            'poster' => $posterUrl,
            'ogImage' => $posterUrl,
            'categories' => $categories,
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
            array_map(static fn (array $m): int => (int)($m['uid'] ?? 0), $relatedRaw),
            static fn (int $uid): bool => $uid > 0
        ));
        $posterRefsByMediaUid = $this->fileReferencePrefetcher->prefetchField($mediaUids, 'poster');

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
                'durationFormatted' => $this->durationFormatter->format($duration),
                'slug' => $slug,
                'mediaType' => (string)($media['media_type'] ?? 'video'),
                'posterReferenceUid' => $posterReferenceUid,
                'poster' => $posterUrl,
                'posterAlt' => $posterAlt,
                'detailUrl' => $this->detailUrlBuilder->build($cObj, $currentPid, $defaultUid, $slug, $languageId),
            ];
        }
        return $cards;
    }

    /**
     * Resolve the first poster FileReference for a media record (used for the
     * OpenGraph/Twitter image meta tags).
     */
    private function resolvePosterReference(int $mediaUid): ?FileReference
    {
        if ($mediaUid <= 0) {
            return null;
        }
        $refs = $this->fileReferencePrefetcher->prefetchField([$mediaUid], 'poster')[$mediaUid] ?? [];
        return $refs[0] ?? null;
    }

    /**
     * @return array{url: ?string}
     */
    private function resolvePosterFile(int $mediaUid): array
    {
        if ($mediaUid <= 0) {
            return ['url' => null];
        }
        $refs = $this->fileReferencePrefetcher->prefetchField([$mediaUid], 'poster')[$mediaUid] ?? [];
        if ($refs === []) {
            return ['url' => null];
        }
        $url = (string)$refs[0]->getPublicUrl();
        return ['url' => $url !== '' ? $url : null];
    }

}
