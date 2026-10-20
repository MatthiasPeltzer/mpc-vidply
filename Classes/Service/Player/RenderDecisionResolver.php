<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Player;

use Mpc\MpcVidply\Enums\MediaMimeType;
use Mpc\MpcVidply\Enums\MediaType;
use Mpc\MpcVidply\Enums\RenderMode;

/**
 * Decides how an assembled track list is rendered: which external service (if
 * any) the player wraps, whether an <audio> or <video> element is used, which
 * template branch applies and which frontend assets have to be loaded.
 *
 * @phpstan-import-type TrackResult from TrackAssembler
 * @phpstan-type AssetFlags array{needsPrivacyLayer: bool, needsVidPlay: bool, needsPlaylist: bool, needsHLS: bool, needsDASH: bool}
 */
final class RenderDecisionResolver
{
    /**
     * External service type of a single external track, null for local media
     * and playlists.
     *
     * @param TrackResult $trackResult
     */
    public function resolveServiceType(array $trackResult): ?string
    {
        if ($trackResult['isPlaylist'] || $trackResult['tracks'] === []) {
            return null;
        }

        $firstTrackType = $trackResult['tracks'][0]['type'] ?? null;
        $mediaType = $firstTrackType !== null ? MediaType::tryFrom($firstTrackType) : null;

        return ($mediaType !== null && $mediaType->isExternal()) ? $mediaType->value : null;
    }

    /**
     * Whether a single external track plays inside the VidPly player (behind
     * the playlist privacy overlay) instead of the provider's own embed. The
     * chrome mode comes from the `embedChrome` / `embedChromeSoundcloud` player
     * options.
     *
     * @param TrackResult $trackResult
     * @param array<string, mixed> $playerOptions
     */
    public function isSingleEmbedInPlayer(?string $serviceType, array $trackResult, array $playerOptions): bool
    {
        if ($serviceType === null || $trackResult['isPlaylist']) {
            return false;
        }

        $chrome = $serviceType === MediaType::SoundCloud->value
            ? ($playerOptions['embedChromeSoundcloud'] ?? 'provider')
            : ($playerOptions['embedChrome'] ?? 'vidply');

        return $chrome !== 'provider';
    }

    /**
     * Derive effective media type for template rendering (<audio> vs <video>).
     * Handles stream types (e.g. audio HLS) that should not render a <video> element.
     *
     * @param TrackResult $trackResult
     */
    public function resolveEffectiveMediaType(array $trackResult): string
    {
        $resolvedMediaType = $trackResult['mediaType'] ?? 'video';
        $tracks = $trackResult['tracks'];

        if ($tracks === []) {
            return $resolvedMediaType;
        }

        $hasVideoTrack = false;
        $hasAudioTrack = false;

        foreach ($tracks as $track) {
            $type = (string)($track['type'] ?? '');
            if ($type === '') {
                continue;
            }

            $kind = (string)($track['kind'] ?? '');
            if ($kind === 'video') {
                $hasVideoTrack = true;
                continue;
            }
            if ($kind === 'audio') {
                $hasAudioTrack = true;
                continue;
            }

            $mediaType = MediaType::tryFrom($type);
            if ($mediaType !== null) {
                $mediaType->isAudioOnly() ? ($hasAudioTrack = true) : ($hasVideoTrack = true);
            } elseif (str_starts_with($type, 'video/')) {
                $hasVideoTrack = true;
            } elseif (str_starts_with($type, 'audio/')) {
                $hasAudioTrack = true;
            }
        }

        if ($hasAudioTrack && !$hasVideoTrack) {
            $resolvedMediaType = 'audio';
        }

        return $resolvedMediaType;
    }

    /**
     * @param TrackResult $trackResult
     */
    public function determineRenderMode(
        ?string $serviceType,
        array $trackResult,
        string $resolvedMediaType,
        bool $singleEmbedInPlayer = false
    ): RenderMode {
        if ($trackResult['tracks'] === []) {
            return RenderMode::Video;
        }

        if ($serviceType !== null && !$trackResult['isPlaylist']) {
            return $singleEmbedInPlayer ? RenderMode::MixedPlaylist : RenderMode::Privacy;
        }

        if ($trackResult['isPlaylist'] && $trackResult['hasExternalMedia']) {
            return RenderMode::MixedPlaylist;
        }

        return $resolvedMediaType === 'audio' ? RenderMode::Audio : RenderMode::Video;
    }

    /**
     * @param TrackResult $trackResult
     * @return AssetFlags
     */
    public function resolveAssetFlags(?string $serviceType, array $trackResult, bool $singleEmbedInPlayer = false): array
    {
        $isPlaylist = $trackResult['isPlaylist'];
        $needsPrivacyLayer = $serviceType !== null || ($isPlaylist && $trackResult['hasExternalMedia']);
        $needsVidPlay = $isPlaylist || $serviceType === null || $singleEmbedInPlayer;
        $needsPlaylist = $isPlaylist || $needsVidPlay;

        $needsHLS = false;
        $needsDASH = false;
        foreach ($trackResult['tracks'] as $track) {
            $typesToCheck = [(string)($track['type'] ?? '')];
            foreach ($track['sources'] ?? [] as $source) {
                $typesToCheck[] = (string)($source['type'] ?? '');
            }
            foreach ($typesToCheck as $t) {
                if (MediaMimeType::isHls($t)) {
                    $needsHLS = true;
                }
                if (MediaMimeType::isDash($t)) {
                    $needsDASH = true;
                }
            }
            if ($needsHLS && $needsDASH) {
                break;
            }
        }

        return [
            'needsPrivacyLayer' => $needsPrivacyLayer,
            'needsVidPlay' => $needsVidPlay,
            'needsPlaylist' => $needsPlaylist,
            'needsHLS' => $needsHLS,
            'needsDASH' => $needsDASH,
        ];
    }
}
