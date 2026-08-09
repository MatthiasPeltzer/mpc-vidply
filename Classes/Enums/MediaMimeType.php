<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Enums;

/**
 * MIME types and file extensions shared by the player, the download resolver and
 * the JSON-LD builder, so all three agree on what counts as a progressive file.
 */
final class MediaMimeType
{
    /**
     * Directly downloadable media files.
     *
     * @var list<string>
     */
    public const PROGRESSIVE = [
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
    public const STREAMING = [
        'application/vnd.apple.mpegurl',
        'application/x-mpegurl',
        'application/dash+xml',
    ];

    /**
     * @var list<string>
     */
    public const HLS = [
        'application/vnd.apple.mpegurl',
        'application/x-mpegurl',
    ];

    public const DASH = 'application/dash+xml';

    /**
     * File extensions that carry a stream manifest or an "external URL" pointer
     * instead of the media itself, so neither their MIME type nor their size can
     * be taken at face value.
     *
     * @var list<string>
     */
    public const NON_PROGRESSIVE_EXTENSIONS = ['externalaudio', 'externalvideo', 'hls', 'm3u8', 'dash', 'mpd'];

    public static function isStreaming(string $mimeType): bool
    {
        return $mimeType !== '' && in_array(strtolower($mimeType), self::STREAMING, true);
    }

    public static function isHls(string $mimeType): bool
    {
        return $mimeType !== '' && in_array(strtolower($mimeType), self::HLS, true);
    }

    public static function isDash(string $mimeType): bool
    {
        return strtolower($mimeType) === self::DASH;
    }
}
