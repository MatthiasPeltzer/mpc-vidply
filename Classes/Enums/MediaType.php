<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Enums;

/**
 * Backed enum for VidPly media types.
 *
 * Corresponds to the `media_type` column in tx_mpcvidply_media.
 */
enum MediaType: string
{
    case Video = 'video';
    case Audio = 'audio';
    case YouTube = 'youtube';
    case Vimeo = 'vimeo';
    case SoundCloud = 'soundcloud';
    case Hls = 'hls';

    /**
     * External services that require a privacy consent layer and embed via iframe.
     */
    public function isExternal(): bool
    {
        return match ($this) {
            self::YouTube, self::Vimeo, self::SoundCloud => true,
            default => false,
        };
    }

    /**
     * Whether this type is inherently audio-only (no video track).
     */
    public function isAudioOnly(): bool
    {
        return match ($this) {
            self::Audio, self::SoundCloud => true,
            default => false,
        };
    }
}
