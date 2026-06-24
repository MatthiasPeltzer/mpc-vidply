<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service;

use Mpc\MpcVidply\Service\SrtToVttConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SrtToVttConverter::class)]
final class SrtToVttConverterTest extends TestCase
{
    private SrtToVttConverter $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new SrtToVttConverter();
    }

    #[Test]
    public function emptyInputFails(): void
    {
        $result = $this->subject->convert('');

        self::assertFalse($result->success);
        self::assertSame('', $result->vtt);
        self::assertNotSame('', $result->errorMessage);
    }

    #[Test]
    public function whitespaceOnlyInputFails(): void
    {
        $result = $this->subject->convert("   \n\n  \r\n");

        self::assertFalse($result->success);
    }

    #[Test]
    public function inputWithoutValidCuesFails(): void
    {
        $result = $this->subject->convert("1\njust some text\nno timestamps here\n");

        self::assertFalse($result->success);
    }

    #[Test]
    public function convertsSimpleCueAndStartsWithWebVttHeader(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:04,000\nHello world\n";

        $result = $this->subject->convert($srt);

        self::assertTrue($result->success);
        self::assertStringStartsWith("WEBVTT\n", $result->vtt);
        self::assertStringContainsString('00:00:01.000 --> 00:00:04.000', $result->vtt);
        self::assertStringContainsString('Hello world', $result->vtt);
    }

    #[Test]
    public function convertsCommaDecimalSeparatorToDot(): void
    {
        $srt = "1\n00:01:02,500 --> 00:01:05,750\nText\n";

        $result = $this->subject->convert($srt);

        self::assertTrue($result->success);
        self::assertStringContainsString('00:01:02.500 --> 00:01:05.750', $result->vtt);
    }

    #[Test]
    public function padsMissingHourComponent(): void
    {
        $srt = "1\n01:02,500 --> 01:05,750\nText\n";

        $result = $this->subject->convert($srt);

        self::assertTrue($result->success);
        self::assertStringContainsString('00:01:02.500 --> 00:01:05.750', $result->vtt);
    }

    #[Test]
    public function stripsUtf8ByteOrderMark(): void
    {
        $srt = "\xEF\xBB\xBF1\n00:00:01,000 --> 00:00:02,000\nWith BOM\n";

        $result = $this->subject->convert($srt);

        self::assertTrue($result->success);
        self::assertStringStartsWith("WEBVTT\n", $result->vtt);
        self::assertStringContainsString('With BOM', $result->vtt);
    }

    #[Test]
    public function stripsAssStylingDirectivesButKeepsText(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:02,000\n{\\an8}Positioned text\n";

        $result = $this->subject->convert($srt);

        self::assertTrue($result->success);
        self::assertStringContainsString('Positioned text', $result->vtt);
        self::assertStringNotContainsString('{\\an8}', $result->vtt);
    }

    #[Test]
    public function stripsLegacyFontTagsButKeepsInnerText(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:02,000\n<font color=\"#fff\">Colored</font>\n";

        $result = $this->subject->convert($srt);

        self::assertTrue($result->success);
        self::assertStringContainsString('Colored', $result->vtt);
        self::assertStringNotContainsString('<font', $result->vtt);
        self::assertStringNotContainsString('</font>', $result->vtt);
    }

    #[Test]
    public function convertsMultipleCues(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:02,000\nFirst\n\n"
            . "2\n00:00:03,000 --> 00:00:04,000\nSecond\n";

        $result = $this->subject->convert($srt);

        self::assertTrue($result->success);
        self::assertStringContainsString('First', $result->vtt);
        self::assertStringContainsString('Second', $result->vtt);
        self::assertStringEndsWith("\n", $result->vtt);
    }
}
