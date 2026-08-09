<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service\Player;

use Mpc\MpcVidply\Service\Player\UrlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UrlSanitizerTest extends TestCase
{
    private UrlSanitizer $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new UrlSanitizer();
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function cssUrlProvider(): array
    {
        return [
            'null stays null' => [null, null],
            'empty string rejected' => ['', null],
            'whitespace only rejected' => ['   ', null],
            'relative path accepted' => ['/fileadmin/x.png', '/fileadmin/x.png'],
            'https url accepted' => ['https://example.com/a.png', 'https://example.com/a.png'],
            'http url accepted' => ['http://example.com/a.png', 'http://example.com/a.png'],
            'trimmed' => ['  /a.png  ', '/a.png'],
            'javascript scheme rejected' => ['javascript:alert(1)', null],
            'data scheme rejected' => ['data:image/png;base64,AAAA', null],
            'single quote rejected' => ["/a').x", null],
            'paren rejected' => ['/a(b.png', null],
            'backtick rejected' => ['/a`b.png', null],
            'angle bracket rejected' => ['/a<b.png', null],
            'newline rejected' => ["/a\nb.png", null],
        ];
    }

    #[Test]
    #[DataProvider('cssUrlProvider')]
    public function sanitizeForCssUrlValidates(?string $input, ?string $expected): void
    {
        self::assertSame($expected, $this->subject->sanitizeForCssUrl($input));
    }

    #[Test]
    public function stripControlCharsRemovesControlBytesButKeepsTabsAndNewlines(): void
    {
        $input = "Hello\x00\x07World\tTabbed\nNewline";

        self::assertSame("HelloWorld\tTabbed\nNewline", $this->subject->stripControlChars($input));
    }

    #[Test]
    public function validateExternalIconUrlAcceptsARelativePath(): void
    {
        self::assertSame('/fileadmin/play.svg', $this->subject->validateExternalIconUrl('/fileadmin/play.svg', []));
    }

    #[Test]
    public function validateExternalIconUrlRejectsAnExternalHostWithoutAnAllowList(): void
    {
        self::assertNull($this->subject->validateExternalIconUrl('https://cdn.example.com/play.svg', []));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function allowListProvider(): array
    {
        return [
            'exact host' => ['https://cdn.example.com/p.svg', 'cdn.example.com', true],
            'other host' => ['https://evil.example/p.svg', 'cdn.example.com', false],
            'wildcard matches subdomain' => ['https://cdn.example.com/p.svg', '*.example.com', true],
            'wildcard matches the base domain' => ['https://example.com/p.svg', '*.example.com', true],
            'wildcard does not match a suffix' => ['https://notexample.com/p.svg', '*.example.com', false],
            'top level wildcard is ignored' => ['https://example.com/p.svg', '*.com', false],
            'non http scheme rejected' => ['ftp://cdn.example.com/p.svg', 'cdn.example.com', false],
        ];
    }

    #[Test]
    #[DataProvider('allowListProvider')]
    public function validateExternalIconUrlHonoursTheConfiguredAllowList(string $url, string $allowList, bool $accepted): void
    {
        $result = $this->subject->validateExternalIconUrl($url, ['allowedPlayIconDomains' => $allowList]);

        self::assertSame($accepted ? $url : null, $result);
    }
}
