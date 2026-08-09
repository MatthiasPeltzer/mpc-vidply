<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\OnlineMedia\Helpers;

/**
 * Online media helper for HLS playlist URLs.
 *
 * Creates a FAL container file with extension ".hls" that stores the playlist URL as online media id.
 */
final class HlsHelper extends AbstractExternalMediaHelper
{
    protected function getSupportedFileExtensions(): array
    {
        return ['m3u8'];
    }

    protected function getAllowedDomainsConfigKey(): string
    {
        return 'allowedVideoDomains';
    }

    protected function getAlreadyExistsExceptionCode(): int
    {
        return 1735063001;
    }

    protected function getDefaultBaseNamePrefix(): string
    {
        return 'stream';
    }

    protected function getFileNameFallback(): string
    {
        return 'hls';
    }
}
