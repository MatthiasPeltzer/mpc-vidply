<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\OnlineMedia\Helpers;

/**
 * Online media helper for directly linked video files on an allow-listed host.
 */
final class ExternalVideoHelper extends AbstractExternalMediaHelper
{
    protected function getSupportedFileExtensions(): array
    {
        return ['mp4', 'webm', 'ogv', 'm4v'];
    }

    protected function getAllowedDomainsConfigKey(): string
    {
        return 'allowedVideoDomains';
    }

    protected function getAlreadyExistsExceptionCode(): int
    {
        return 1735061001;
    }

    protected function getDefaultBaseNamePrefix(): string
    {
        return 'video';
    }

    protected function getFileNameFallback(): string
    {
        return 'external-video';
    }
}
