<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service\Player;

use Mpc\MpcVidply\Service\Player\InlineSvgProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InlineSvgProviderTest extends TestCase
{
    private InlineSvgProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new InlineSvgProvider();
    }

    #[Test]
    public function returnsNullForMissingFile(): void
    {
        self::assertNull($this->subject->fromAbsolutePath('/path/does/not/exist.svg'));
    }

    #[Test]
    public function returnsNullForANonExtensionPath(): void
    {
        self::assertNull($this->subject->fromExtensionPath('/fileadmin/play.svg'));
        self::assertNull($this->subject->fromExtensionPath('EXT:mpc_vidply/Resources/Public/Icons/play.png'));
    }

    #[Test]
    public function stripsDangerousContent(): void
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

        $result = $this->sanitizeSvg($svg);

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
    public function removesFixedWidthAndHeight(): void
    {
        $result = $this->sanitizeSvg(
            '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><polygon points="0,0 10,0 5,10" /></svg>'
        );

        self::assertIsString($result);
        self::assertStringNotContainsString('width="80"', $result);
        self::assertStringNotContainsString('height="80"', $result);
    }

    private function sanitizeSvg(string $svg): ?string
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'vidply_svg_') . '.svg';
        file_put_contents($path, $svg);

        try {
            return $this->subject->fromAbsolutePath($path);
        } finally {
            @unlink($path);
        }
    }
}
