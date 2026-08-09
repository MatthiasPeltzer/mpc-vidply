<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\OnlineMedia\Helpers;

/**
 * Online media helper for directly linked audio files on an allow-listed host.
 */
final class ExternalAudioHelper extends AbstractExternalMediaHelper
{
    protected function getSupportedFileExtensions(): array
    {
        // `.m3u8` is included so radio streams can be imported as audio.
        return ['mp3', 'wav', 'm4a', 'aac', 'flac', 'oga', 'm3u8'];
    }

    protected function getAllowedDomainsConfigKey(): string
    {
        return 'allowedAudioDomains';
    }

    protected function getAlreadyExistsExceptionCode(): int
    {
        return 1735061002;
    }

    protected function getDefaultBaseNamePrefix(): string
    {
        return 'audio';
    }

    protected function getFileNameFallback(): string
    {
        return 'external-audio';
    }
}
