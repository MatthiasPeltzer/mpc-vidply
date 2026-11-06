<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Streaming;

use Mpc\MpcVidply\Dto\MediaProbeResult;

/**
 * Reads dimensions, duration and audio presence of a video with ffprobe.
 */
final readonly class FfprobeReader
{
    public function __construct(
        private ProcessRunnerInterface $processRunner,
        private StreamingSettings $settings,
    ) {}

    /**
     * @throws \RuntimeException when ffprobe fails or finds no video stream
     */
    public function probe(string $localPath): MediaProbeResult
    {
        $result = $this->processRunner->run([
            $this->settings->get()->ffprobePath,
            '-v', 'error',
            '-print_format', 'json',
            '-show_streams',
            '-show_format',
            $localPath,
        ], 60.0);

        if (!$result->isSuccessful()) {
            throw new \RuntimeException('ffprobe failed: ' . $result->errorTail(), 1762156801);
        }

        return self::parse($result->output);
    }

    /**
     * @throws \RuntimeException
     */
    public static function parse(string $json): MediaProbeResult
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('ffprobe returned invalid JSON.', 1762156802, $exception);
        }
        if (!is_array($data)) {
            throw new \RuntimeException('ffprobe returned invalid JSON.', 1762156802);
        }

        $video = null;
        $hasAudio = false;
        $streams = is_array($data['streams'] ?? null) ? $data['streams'] : [];
        foreach ($streams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $codecType = $stream['codec_type'] ?? '';
            $isCoverArt = (int)($stream['disposition']['attached_pic'] ?? 0) === 1;
            if ($codecType === 'video' && $video === null && !$isCoverArt) {
                $video = $stream;
            } elseif ($codecType === 'audio') {
                $hasAudio = true;
            }
        }

        if ($video === null) {
            throw new \RuntimeException('The file contains no video stream.', 1762156803);
        }

        $width = (int)($video['width'] ?? 0);
        $height = (int)($video['height'] ?? 0);
        if ($width <= 0 || $height <= 0) {
            throw new \RuntimeException('ffprobe reported no video dimensions.', 1762156804);
        }

        // ffmpeg auto-rotates on decode, so a 90° phone clip comes out portrait.
        if (abs(self::rotation($video)) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        $duration = (float)($data['format']['duration'] ?? $video['duration'] ?? 0);

        return new MediaProbeResult($width, $height, max(0.0, $duration), $hasAudio);
    }

    /**
     * @param array<mixed> $stream
     */
    private static function rotation(array $stream): int
    {
        $sideData = is_array($stream['side_data_list'] ?? null) ? $stream['side_data_list'] : [];
        foreach ($sideData as $entry) {
            if (is_array($entry) && isset($entry['rotation']) && is_numeric($entry['rotation'])) {
                return (int)$entry['rotation'];
            }
        }
        $rotate = $stream['tags']['rotate'] ?? null;

        return is_numeric($rotate) ? (int)$rotate : 0;
    }
}
