<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Dto;

/**
 * Streaming conversion settings from the extension configuration, with every
 * value normalised so the services never have to re-validate them.
 */
final readonly class StreamingConfiguration
{
    public const DEFAULT_TARGET_FOLDER = '1:/user_upload/';
    public const DEFAULT_RENDITIONS = [1080, 720, 480, 360];
    public const DEFAULT_SIGN_LANGUAGE_RENDITIONS = [480, 360];
    public const DEFAULT_SEGMENT_DURATION = 4;

    /**
     * @param list<int> $renditions Heights in pixels, highest first
     * @param list<int> $signLanguageRenditions Heights in pixels, highest first
     */
    public function __construct(
        public bool $enabled,
        public string $ffmpegPath,
        public string $ffprobePath,
        public string $targetFolder,
        public array $renditions,
        public array $signLanguageRenditions,
        public int $segmentDuration,
        public int $threads,
    ) {}

    /**
     * @param array<string, mixed> $extensionConfiguration
     */
    public static function fromArray(array $extensionConfiguration): self
    {
        $ffmpegPath = trim(self::stringValue($extensionConfiguration['ffmpegPath'] ?? ''));
        $ffprobePath = trim(self::stringValue($extensionConfiguration['ffprobePath'] ?? ''));
        $targetFolder = trim(self::stringValue($extensionConfiguration['streamingTargetFolder'] ?? ''));
        $segmentDuration = (int)self::stringValue($extensionConfiguration['streamingSegmentDuration'] ?? '');

        return new self(
            enabled: (bool)($extensionConfiguration['streamingEnabled'] ?? false),
            ffmpegPath: $ffmpegPath !== '' ? $ffmpegPath : 'ffmpeg',
            ffprobePath: $ffprobePath !== '' ? $ffprobePath : 'ffprobe',
            targetFolder: $targetFolder !== '' ? $targetFolder : self::DEFAULT_TARGET_FOLDER,
            renditions: self::parseHeights(
                self::stringValue($extensionConfiguration['streamingRenditions'] ?? ''),
                self::DEFAULT_RENDITIONS
            ),
            signLanguageRenditions: self::parseHeights(
                self::stringValue($extensionConfiguration['streamingSignLanguageRenditions'] ?? ''),
                self::DEFAULT_SIGN_LANGUAGE_RENDITIONS
            ),
            segmentDuration: $segmentDuration >= 1 && $segmentDuration <= 30 ? $segmentDuration : self::DEFAULT_SEGMENT_DURATION,
            threads: max(0, (int)self::stringValue($extensionConfiguration['streamingThreads'] ?? '')),
        );
    }

    /**
     * Heights from a comma list such as "1080, 720p,480". Values outside
     * 144–4320 px are dropped; odd heights are rounded down to even ones,
     * since H.264 with 4:2:0 chroma needs even dimensions.
     *
     * @param list<int> $default
     * @return list<int>
     */
    public static function parseHeights(string $value, array $default): array
    {
        $heights = [];
        foreach (preg_split('/[\s,;]+/', $value) ?: [] as $part) {
            $part = rtrim(strtolower($part), 'p');
            if ($part === '' || !ctype_digit($part)) {
                continue;
            }
            $height = (int)$part;
            if ($height < 144 || $height > 4320) {
                continue;
            }
            $heights[] = $height - ($height % 2);
        }

        $heights = array_values(array_unique($heights));
        rsort($heights);

        return $heights !== [] ? $heights : $default;
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }
}
