<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Streaming;

use TYPO3\CMS\Core\Charset\CharsetConverter;

/**
 * File and folder names of the conversion output.
 */
final readonly class StreamNaming
{
    private const MAX_LENGTH = 60;

    public function __construct(
        private CharsetConverter $charsetConverter,
    ) {}

    /**
     * URL- and filesystem-safe slug of a file name without its extension,
     * e.g. "Mein Video (final).MP4" → "mein-video-final".
     */
    public function baseName(string $fileName, string $fallback = 'video'): string
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $name = $this->charsetConverter->utf8_char_mapping($name);
        $name = strtolower($name);
        $name = (string)preg_replace('/[^a-z0-9]+/', '-', $name);
        $name = trim(substr(trim($name, '-'), 0, self::MAX_LENGTH), '-');

        return $name !== '' ? $name : $fallback;
    }

    /**
     * Output folder of one record: "<source-file-slug>-<uid>". The uid keeps
     * two records with the same file name apart.
     */
    public function outputFolderName(string $sourceFileName, int $recordUid): string
    {
        return $this->baseName($sourceFileName) . '-' . $recordUid;
    }

    /**
     * Sub folder of an audio-description or sign-language source, e.g.
     * "ad-mein-video-ad" or "sign-dgs".
     */
    public function accessibilityFolderName(string $prefix, string $sourceFileName): string
    {
        return $prefix . '-' . $this->baseName($sourceFileName);
    }
}
