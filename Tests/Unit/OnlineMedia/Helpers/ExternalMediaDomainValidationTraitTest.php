<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\OnlineMedia\Helpers;

use Mpc\MpcVidply\OnlineMedia\Helpers\ExternalMediaDomainValidationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class ExternalMediaDomainValidationTraitTest extends TestCase
{
    /**
     * Minimal consumer of the trait that exposes its private logic for testing.
     */
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class () {
            use ExternalMediaDomainValidationTrait;

            // The trait declares this as an abstract private dependency. It is
            // only used by getAllowedDomains(), which these tests do not call,
            // so a throwing stub is sufficient to satisfy the contract.
            private function getExtensionConfiguration(): ExtensionConfiguration
            {
                throw new \LogicException('Not used in these tests.');
            }

            /**
             * @param string[] $patterns
             */
            public function hostAllowed(string $scheme, string $host, array $patterns): bool
            {
                return $this->isHostAllowed($scheme, $host, $patterns);
            }

            public function fileName(string $base, string $ext, string $fallback = 'media'): string
            {
                return $this->buildFileName($base, $ext, $fallback);
            }
        };
    }

    #[Test]
    public function emptyPatternListRejectsEverything(): void
    {
        self::assertFalse($this->subject->hostAllowed('https', 'example.com', []));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string[], 3: bool}>
     */
    public static function hostProvider(): array
    {
        return [
            'exact host match' => ['https', 'example.com', ['example.com'], true],
            'exact host mismatch' => ['https', 'evil.com', ['example.com'], false],
            'wildcard matches base domain' => ['https', 'example.com', ['*.example.com'], true],
            'wildcard matches subdomain' => ['https', 'cdn.example.com', ['*.example.com'], true],
            'wildcard matches deep subdomain' => ['https', 'a.b.example.com', ['*.example.com'], true],
            'wildcard does not match other domain' => ['https', 'example.org', ['*.example.com'], false],
            'wildcard does not match suffix trick' => ['https', 'notexample.com', ['*.example.com'], false],
            'bare tld wildcard rejected' => ['https', 'anything.com', ['*.com'], false],
            'scheme prefix matches same scheme' => ['https', 'example.com', ['https://example.com'], true],
            'scheme prefix rejects other scheme' => ['http', 'example.com', ['https://example.com'], false],
            'scheme prefix wildcard host' => ['https', 'cdn.example.com', ['https://*.example.com'], true],
            'case insensitive pattern' => ['https', 'example.com', ['EXAMPLE.COM'], true],
        ];
    }

    /**
     * @param string[] $patterns
     */
    #[Test]
    #[DataProvider('hostProvider')]
    public function isHostAllowedEvaluatesPatterns(string $scheme, string $host, array $patterns, bool $expected): void
    {
        self::assertSame($expected, $this->subject->hostAllowed($scheme, $host, $patterns));
    }

    #[Test]
    public function buildFileNameSanitizesUnsafeCharacters(): void
    {
        // Spaces and special characters collapse to a single underscore.
        self::assertSame('My_Cool_Clip.vtt', $this->subject->fileName('My Cool Clip', 'vtt'));
        self::assertSame('a_b_c.vtt', $this->subject->fileName('a@b#c', 'vtt'));
    }

    #[Test]
    public function buildFileNameStripsExistingExtensionFromBase(): void
    {
        self::assertSame('clip.mp4', $this->subject->fileName('clip.webm', 'mp4'));
    }

    #[Test]
    public function buildFileNameFallsBackForEmptyBase(): void
    {
        self::assertSame('media.mp4', $this->subject->fileName('', 'mp4'));
    }

    #[Test]
    public function buildFileNameUsesCustomFallback(): void
    {
        self::assertSame('video.mp4', $this->subject->fileName('   ', 'mp4', 'video'));
    }
}
