<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\DataProcessing;

use Mpc\MpcVidply\DataProcessing\VidPlyProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure sanitization helpers of {@see VidPlyProcessor}.
 *
 * These helpers are private and have no dependency on the (heavy) constructor
 * services, so we instantiate the processor without invoking its constructor
 * and reach the methods via reflection.
 */
final class VidPlyProcessorSanitizationTest extends TestCase
{
    private VidPlyProcessor $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = (new \ReflectionClass(VidPlyProcessor::class))->newInstanceWithoutConstructor();
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        // PHP 8.1+ exposes private methods through reflection without
        // setAccessible(), which is deprecated as of PHP 8.5.
        return (new \ReflectionMethod(VidPlyProcessor::class, $method))->invoke($this->subject, ...$args);
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
    public function sanitizeUrlForCssUrlValidates(?string $input, ?string $expected): void
    {
        self::assertSame($expected, $this->invoke('sanitizeUrlForCssUrl', $input));
    }

    #[Test]
    public function loadAndSanitizeSvgReturnsNullForMissingFile(): void
    {
        self::assertNull($this->invoke('loadAndSanitizeSvgFromAbsolutePath', '/path/does/not/exist.svg'));
    }

    #[Test]
    public function loadAndSanitizeSvgStripsDangerousContent(): void
    {
        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="80" height="80" onload="alert(1)">
    <script>alert(2)</script>
    <style>.x{fill:red}</style>
    <image href="https://evil.example/x.png" />
    <use href="https://evil.example/sprite.svg#a" />
    <use href="#local" />
    <a href="javascript:alert(3)"><polygon points="0,0 10,0 5,10" /></a>
</svg>
SVG;

        $path = (string)tempnam(sys_get_temp_dir(), 'vidply_svg_') . '.svg';
        file_put_contents($path, $svg);

        try {
            $result = $this->invoke('loadAndSanitizeSvgFromAbsolutePath', $path);
        } finally {
            @unlink($path);
        }

        self::assertIsString($result);
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('onload', $result);
        self::assertStringNotContainsString('<style', $result);
        self::assertStringNotContainsString('<image', $result);
        self::assertStringNotContainsString('evil.example', $result);
        self::assertStringNotContainsString('javascript:', $result);

        // Sanitized, branded output keeps harmless geometry and gets the helper class.
        self::assertStringContainsString('mpc-vidply-custom-play-icon', $result);
        self::assertStringContainsString('aria-hidden="true"', $result);
        self::assertStringContainsString('<polygon', $result);
        // Local fragment <use> reference is preserved.
        self::assertStringContainsString('#local', $result);
    }

    #[Test]
    public function loadAndSanitizeSvgRemovesFixedWidthAndHeight(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><polygon points="0,0 10,0 5,10" /></svg>';

        $path = (string)tempnam(sys_get_temp_dir(), 'vidply_svg_') . '.svg';
        file_put_contents($path, $svg);

        try {
            $result = $this->invoke('loadAndSanitizeSvgFromAbsolutePath', $path);
        } finally {
            @unlink($path);
        }

        self::assertIsString($result);
        self::assertStringNotContainsString('width="80"', $result);
        self::assertStringNotContainsString('height="80"', $result);
    }
}
