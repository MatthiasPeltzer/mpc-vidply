<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Utility;

use Mpc\MpcVidply\Utility\HttpUrlGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpUrlGuard::class)]
final class HttpUrlGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedUrlProvider(): array
    {
        return [
            'empty' => [''],
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html,<script>alert(1)</script>'],
            'ftp scheme' => ['ftp://cdn.example.com/a.mp3'],
            'relative path' => ['/fileadmin/a.mp3'],
            'protocol relative' => ['//cdn.example.com/a.mp3'],
            'backslash host confusion' => ['https://evil.tld\\@cdn.example.com/a.m3u8'],
            'userinfo' => ['https://user@cdn.example.com/a.m3u8'],
            'user and password' => ['https://user:pass@cdn.example.com/a.m3u8'],
            'inner whitespace' => ['https://cdn.example.com/a b.mp3'],
            'tab' => ["https://cdn.example.com/\ta.mp3"],
            'newline' => ["https://cdn.example.com/a.mp3\nx"],
            'null byte' => ["https://cdn.example.com/a.mp3\0"],
        ];
    }

    #[Test]
    #[DataProvider('rejectedUrlProvider')]
    public function parseRejectsUnsafeUrls(string $url): void
    {
        self::assertNull(HttpUrlGuard::parse($url));
    }

    #[Test]
    public function parseNormalizesSchemeAndHost(): void
    {
        self::assertSame(
            ['scheme' => 'https', 'host' => 'cdn.example.com', 'port' => 8443, 'path' => '/a.m3u8', 'query' => 'x=1', 'fragment' => 't'],
            HttpUrlGuard::parse('HTTPS://CDN.Example.com:8443/a.m3u8?x=1#t')
        );
    }

    #[Test]
    public function buildRoundTripsParsedUrl(): void
    {
        $parts = HttpUrlGuard::parse('https://cdn.example.com:8443/live/a.m3u8?token=abc#t');
        self::assertNotNull($parts);
        self::assertSame('https://cdn.example.com:8443/live/a.m3u8?token=abc#t', HttpUrlGuard::build($parts));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function linkTargetProvider(): array
    {
        return [
            'absolute https' => ['https://cdn.example.com/a.mp3', true],
            'root relative' => ['/fileadmin/a.mp3', true],
            'relative' => ['fileadmin/a.mp3', true],
            'javascript' => ['javascript:alert(1)', false],
            'uppercase javascript' => ['JavaScript:alert(1)', false],
            'protocol relative' => ['//evil.example/a.mp3', false],
            'backslash' => ['/\\evil.example/a.mp3', false],
            'empty' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('linkTargetProvider')]
    public function isSafeLinkTargetAcceptsOnlyHttpAndRelativeUrls(string $url, bool $expected): void
    {
        self::assertSame($expected, HttpUrlGuard::isSafeLinkTarget($url));
    }
}
