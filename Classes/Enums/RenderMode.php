<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Enums;

/**
 * Backed enum for Fluid template render modes.
 *
 * Determines which player variant the template renders:
 *  - Privacy:       single external service with consent layer
 *  - MixedPlaylist: playlist containing external media
 *  - Audio:         local/HLS audio player
 *  - Video:         local/HLS video player (also default for empty CE)
 */
enum RenderMode: string
{
    case Privacy = 'privacy';
    case MixedPlaylist = 'mixedPlaylist';
    case Audio = 'audio';
    case Video = 'video';
}
