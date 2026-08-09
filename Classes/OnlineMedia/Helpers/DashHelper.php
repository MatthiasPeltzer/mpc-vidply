<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\OnlineMedia\Helpers;

/**
 * Online media helper for DASH manifest URLs (.mpd).
 *
 * Creates a FAL container file with extension ".dash" that stores the manifest URL as online media id.
 */
final class DashHelper extends AbstractExternalMediaHelper
{
    protected function getSupportedFileExtensions(): array
    {
        return ['mpd'];
    }

    protected function getAllowedDomainsConfigKey(): string
    {
        return 'allowedVideoDomains';
    }

    protected function getAlreadyExistsExceptionCode(): int
    {
        return 1735063002;
    }

    protected function getDefaultBaseNamePrefix(): string
    {
        return 'stream';
    }

    protected function getFileNameFallback(): string
    {
        return 'dash';
    }
}
