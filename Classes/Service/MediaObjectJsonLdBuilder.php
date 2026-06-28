<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use Mpc\MpcVidply\Enums\MediaType;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Builds schema.org VideoObject / AudioObject JSON-LD for VidPly media pages.
 *
 * Output is a json_encode()-d string safe for embedding in a Fluid template via
 * {@see \TYPO3Fluid\Fluid\ViewHelpers\Format\RawViewHelper}.
 */
final class MediaObjectJsonLdBuilder
{
    /** @var list<string> */
    private const PROGRESSIVE_MIME_TYPES = [
        'video/mp4',
        'video/webm',
        'audio/mpeg',
        'audio/ogg',
    ];

    /**
     * Adaptive-streaming manifest types. These are not progressive files and are
     * not eligible as a {@code contentUrl} for Google video rich results.
     *
     * @var list<string>
     */
    private const STREAMING_MIME_TYPES = [
        'application/vnd.apple.mpegurl',
        'application/x-mpegurl',
        'application/dash+xml',
    ];

    /**
     * JSON encoding flags shared by the standalone and mp-core merge output paths so
     * both escape HTML-sensitive characters identically while keeping slashes and
     * Unicode readable.
     */
    public const JSON_ENCODE_FLAGS = JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    /**
     * @param array<string, mixed> $media
     * @param array<string, mixed>|null $vidply
     * @return array<string, mixed>|null Graph node without @context (for @graph merging)
     */
    public function buildGraphNode(
        array $media,
        ServerRequestInterface $request,
        ?array $vidply,
        ?string $pageUrl,
        ?string $posterUrl,
        ?string $entityId = null,
        ?string $itemUrl = null,
    ): ?array {
        if (!$this->isStructuredDataEnabled($request)) {
            return null;
        }

        $title = trim((string)($media['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $mediaType = (string)($media['media_type'] ?? 'video');
        $description = $this->resolveDescription($media);
        $duration = (int)($media['duration'] ?? 0);
        $uploadTimestamp = (int)($media['crdate'] ?? 0);

        $absolutePageUrl = $this->makeAbsoluteUrl($request, $pageUrl);
        $absolutePosterUrl = $this->makeAbsoluteUrl($request, $posterUrl);
        $mediaUrls = $this->resolveMediaUrls($vidply, $mediaType, $absolutePageUrl);
        $contentUrl = $this->makeAbsoluteUrl($request, $mediaUrls['contentUrl']);
        $embedUrl = $this->makeAbsoluteUrl($request, $mediaUrls['embedUrl']);

        $isAudio = MediaType::tryFrom($mediaType)?->isAudioOnly() ?? false;
        $data = [
            '@type' => $isAudio ? 'AudioObject' : 'VideoObject',
            'name' => $title,
        ];

        if ($entityId !== null && $entityId !== '') {
            $data['@id'] = $entityId;
        } elseif ($absolutePageUrl !== '') {
            $data['@id'] = $absolutePageUrl . '#media';
        }

        $watchUrl = $this->makeAbsoluteUrl($request, $itemUrl ?? $pageUrl);
        if ($watchUrl !== '') {
            $data['url'] = $watchUrl;
        }
        if ($description !== '') {
            $data['description'] = $description;
        }
        if ($duration > 0) {
            $data['duration'] = $this->toIsoDuration($duration);
        }
        if ($absolutePosterUrl !== '') {
            $data['thumbnailUrl'] = $absolutePosterUrl;
        }
        if ($uploadTimestamp > 0) {
            $data['uploadDate'] = gmdate('Y-m-d\TH:i:s\Z', $uploadTimestamp);
        }
        if ($contentUrl !== '') {
            $data['contentUrl'] = $contentUrl;
        }
        if ($embedUrl !== '') {
            $data['embedUrl'] = $embedUrl;
        }

        return $data;
    }

    /**
     * @param list<array{
     *     media: array<string, mixed>,
     *     vidply: array<string, mixed>,
     *     pageUrl: string,
     *     itemUrl: string,
     *     posterUrl: ?string
     * }> $items
     * @return array<string, mixed>|null
     */
    public function buildItemListGraphNode(
        ServerRequestInterface $request,
        string $pageUrl,
        string $listName,
        array $items,
    ): ?array {
        if (!$this->isStructuredDataEnabled($request)) {
            return null;
        }

        $absolutePageUrl = $this->makeAbsoluteUrl($request, $pageUrl);
        if ($absolutePageUrl === '') {
            return null;
        }

        $itemListElement = [];
        $position = 1;
        foreach ($items as $item) {
            $defaultUid = (int)($item['media']['l10n_parent'] ?? 0);
            if ($defaultUid <= 0) {
                $defaultUid = (int)($item['media']['uid'] ?? 0);
            }
            if ($defaultUid <= 0) {
                continue;
            }

            $videoNode = $this->buildGraphNode(
                $item['media'],
                $request,
                $item['vidply'],
                $item['pageUrl'],
                $item['posterUrl'],
                $absolutePageUrl . '#media-' . $defaultUid,
                $item['itemUrl'],
            );
            if ($videoNode === null) {
                continue;
            }

            $itemListElement[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'item' => $videoNode,
            ];
            ++$position;
        }

        if ($itemListElement === []) {
            return null;
        }

        $list = [
            '@type' => 'ItemList',
            '@id' => $absolutePageUrl . '#media-list',
            'numberOfItems' => count($itemListElement),
            'itemListElement' => $itemListElement,
        ];

        if ($listName !== '') {
            $list['name'] = $listName;
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $node
     */
    public function encodeStandaloneGraphNode(array $node): ?string
    {
        try {
            return json_encode(
                ['@context' => 'https://schema.org'] + $node,
                self::JSON_ENCODE_FLAGS
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $media
     */
    private function resolveDescription(array $media): string
    {
        $description = trim(strip_tags((string)($media['description'] ?? '')));
        if ($description !== '') {
            return $description;
        }

        $longDescription = trim(strip_tags((string)($media['long_description'] ?? '')));
        if ($longDescription === '') {
            return '';
        }

        return mb_strlen($longDescription) > 500
            ? mb_substr($longDescription, 0, 497) . '...'
            : $longDescription;
    }

    /**
     * @param array<string, mixed>|null $vidply
     * @return array{contentUrl: ?string, embedUrl: ?string}
     */
    public function resolveMediaUrls(?array $vidply, string $mediaType, ?string $pageUrl): array
    {
        $type = MediaType::tryFrom($mediaType);
        if ($type === null) {
            return ['contentUrl' => null, 'embedUrl' => $pageUrl];
        }

        $track = $this->resolveFirstTrack($vidply);
        if ($track === null) {
            return ['contentUrl' => null, 'embedUrl' => $pageUrl];
        }

        $src = trim((string)($track['src'] ?? ''));

        return match ($type) {
            MediaType::YouTube => $this->resolveYouTubeUrls($src, $pageUrl),
            MediaType::Vimeo => $this->resolveVimeoUrls($src, $pageUrl),
            MediaType::SoundCloud => [
                'contentUrl' => $src !== '' ? $src : null,
                'embedUrl' => null,
            ],
            MediaType::Video, MediaType::Audio => $this->resolveLocalMediaUrls($vidply, $track, $pageUrl),
        };
    }

    /**
     * @param array<string, mixed>|null $vidply
     * @return array<string, mixed>|null
     */
    private function resolveFirstTrack(?array $vidply): ?array
    {
        if ($vidply === null) {
            return null;
        }
        $tracks = $vidply['tracks'] ?? null;
        if (!is_array($tracks) || $tracks === []) {
            return null;
        }
        $firstTrack = $tracks[0] ?? null;

        return is_array($firstTrack) ? $firstTrack : null;
    }

    /**
     * @return array{contentUrl: ?string, embedUrl: ?string}
     */
    private function resolveYouTubeUrls(string $src, ?string $pageUrl): array
    {
        $videoId = $this->extractYouTubeId($src);
        if ($videoId === null) {
            return ['contentUrl' => $src !== '' ? $src : null, 'embedUrl' => $pageUrl];
        }

        return [
            'contentUrl' => 'https://www.youtube.com/watch?v=' . rawurlencode($videoId),
            'embedUrl' => 'https://www.youtube.com/embed/' . rawurlencode($videoId),
        ];
    }

    /**
     * @return array{contentUrl: ?string, embedUrl: ?string}
     */
    private function resolveVimeoUrls(string $src, ?string $pageUrl): array
    {
        $videoId = $this->extractVimeoId($src);
        if ($videoId === null) {
            return ['contentUrl' => $src !== '' ? $src : null, 'embedUrl' => $pageUrl];
        }

        return [
            'contentUrl' => 'https://vimeo.com/' . rawurlencode($videoId),
            'embedUrl' => 'https://player.vimeo.com/video/' . rawurlencode($videoId),
        ];
    }

    /**
     * @param array<string, mixed>|null $vidply
     * @param array<string, mixed> $track
     * @return array{contentUrl: ?string, embedUrl: ?string}
     */
    private function resolveLocalMediaUrls(?array $vidply, array $track, ?string $pageUrl): array
    {
        $downloadUrl = trim((string)($vidply['downloadUrl'] ?? ''));
        if ($downloadUrl !== '') {
            return ['contentUrl' => $downloadUrl, 'embedUrl' => $pageUrl];
        }

        $progressiveUrl = $this->resolveProgressiveSourceUrl($track);
        if ($progressiveUrl !== null) {
            return ['contentUrl' => $progressiveUrl, 'embedUrl' => $pageUrl];
        }

        // Adaptive-streaming manifests (HLS/DASH) are not valid progressive
        // contentUrl values for video rich results, so omit contentUrl for them.
        $src = trim((string)($track['src'] ?? ''));
        if ($src === '' || $this->isStreamingTrack($track)) {
            return ['contentUrl' => null, 'embedUrl' => $pageUrl];
        }

        return ['contentUrl' => $src, 'embedUrl' => $pageUrl];
    }

    /**
     * @param array<string, mixed> $track
     */
    private function isStreamingTrack(array $track): bool
    {
        $candidates = [(string)($track['type'] ?? '')];
        foreach ($track['sources'] ?? [] as $source) {
            if (is_array($source)) {
                $candidates[] = (string)($source['type'] ?? '');
            }
        }
        foreach ($candidates as $type) {
            if ($type !== '' && in_array(strtolower($type), self::STREAMING_MIME_TYPES, true)) {
                return true;
            }
        }

        $src = strtolower(trim((string)($track['src'] ?? '')));

        return str_contains($src, '.m3u8') || str_contains($src, '.mpd');
    }

    /**
     * @param array<string, mixed> $track
     */
    private function resolveProgressiveSourceUrl(array $track): ?string
    {
        $sources = $track['sources'] ?? null;
        if (is_array($sources)) {
            foreach ($sources as $source) {
                if (!is_array($source)) {
                    continue;
                }
                $type = (string)($source['type'] ?? '');
                $src = trim((string)($source['src'] ?? ''));
                if ($src !== '' && in_array($type, self::PROGRESSIVE_MIME_TYPES, true)) {
                    return $src;
                }
            }
        }

        $src = trim((string)($track['src'] ?? ''));
        $type = (string)($track['type'] ?? '');
        if ($src !== '' && in_array($type, self::PROGRESSIVE_MIME_TYPES, true)) {
            return $src;
        }

        return null;
    }

    private function extractYouTubeId(string $src): ?string
    {
        if ($src === '') {
            return null;
        }

        if (preg_match(
            '~(?:youtube\.com/(?:watch\?v=|embed/|v/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~',
            $src,
            $matches
        ) === 1) {
            return $matches[1];
        }

        if (preg_match('~^[A-Za-z0-9_-]{11}$~', $src) === 1) {
            return $src;
        }

        return null;
    }

    private function extractVimeoId(string $src): ?string
    {
        if ($src === '') {
            return null;
        }

        if (preg_match('~(?:vimeo\.com/(?:video/)?|player\.vimeo\.com/video/)(\d+)~', $src, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('~^\d+$~', $src) === 1) {
            return $src;
        }

        return null;
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

    private function isStructuredDataEnabled(ServerRequestInterface $request): bool
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return true;
        }

        return filter_var(
            $site->getSettings()->get('structuredDataEnabled') ?? true,
            FILTER_VALIDATE_BOOLEAN
        );
    }

    private function makeAbsoluteUrl(ServerRequestInterface $request, ?string $url): string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams === null) {
            return $url;
        }

        return rtrim($normalizedParams->getSiteUrl(), '/') . '/' . ltrim($url, '/');
    }
}
