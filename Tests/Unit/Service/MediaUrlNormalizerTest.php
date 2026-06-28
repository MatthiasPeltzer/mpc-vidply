<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service;

use Mpc\MpcVidply\Service\MediaUrlNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MediaUrlNormalizer::class)]
final class MediaUrlNormalizerTest extends TestCase
{
    private MediaUrlNormalizer $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new MediaUrlNormalizer();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function youTubeIdProvider(): array
    {
        return [
            'watch url' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'shorts url' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'youtu.be' => ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'embed url' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'bare id' => ['dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
        ];
    }

    #[Test]
    #[DataProvider('youTubeIdProvider')]
    public function extractYouTubeIdSupportsCommonVariants(string $input, string $expected): void
    {
        self::assertSame($expected, $this->subject->extractYouTubeId($input));
    }

    #[Test]
    public function normalizeStripsTrackingParametersFromExternalUrls(): void
    {
        $normalized = $this->subject->normalize(
            'https://cdn.example.com/video.mp4?utm_source=test&token=abc123'
        );

        self::assertSame(
            'https://cdn.example.com/video.mp4?token=abc123',
            $normalized
        );
    }

    #[Test]
    public function normalizeAddsHttpsSchemeForDomainLikeInput(): void
    {
        self::assertSame(
            'https://youtu.be/dQw4w9WgXcQ',
            $this->subject->normalize('youtu.be/dQw4w9WgXcQ')
        );
    }

    #[Test]
    public function toCanonicalYouTubeWatchUrlNormalizesShortLinks(): void
    {
        self::assertSame(
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            $this->subject->toCanonicalYouTubeWatchUrl('https://youtu.be/dQw4w9WgXcQ')
        );
    }
}
