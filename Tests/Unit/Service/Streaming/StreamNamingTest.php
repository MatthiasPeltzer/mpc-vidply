<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service\Streaming;

use Mpc\MpcVidply\Service\Streaming\StreamNaming;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Charset\CharsetConverter;
use TYPO3\CMS\Core\Charset\CharsetProvider;

#[CoversClass(StreamNaming::class)]
final class StreamNamingTest extends TestCase
{
    private StreamNaming $subject;

    protected function setUp(): void
    {
        parent::setUp();
        // TYPO3 14 injects a CharsetProvider; 13 has no constructor arguments.
        $converter = class_exists(CharsetProvider::class)
            ? new CharsetConverter(new CharsetProvider())
            : new CharsetConverter();
        $this->subject = new StreamNaming($converter);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function baseNameProvider(): array
    {
        return [
            'spaces and brackets' => ['Mein Video (final).MP4', 'mein-video-final'],
            'umlauts transliterated' => ['Gebärdensprache Übersicht.mp4', 'gebaerdensprache-uebersicht'],
            'repeated separators collapse' => ['a__b--c  d.mov', 'a-b-c-d'],
            'only the last extension is dropped' => ['clip.v2.mp4', 'clip-v2'],
            'nothing usable falls back' => ['???.mp4', 'video'],
        ];
    }

    #[Test]
    #[DataProvider('baseNameProvider')]
    public function baseNameIsAUrlSafeSlug(string $fileName, string $expected): void
    {
        self::assertSame($expected, $this->subject->baseName($fileName));
    }

    #[Test]
    public function baseNameIsLimitedToSixtyCharactersWithoutTrailingDash(): void
    {
        $name = $this->subject->baseName(str_repeat('ab ', 40) . '.mp4');

        self::assertLessThanOrEqual(60, strlen($name));
        self::assertStringEndsNotWith('-', $name);
    }

    #[Test]
    public function outputFolderCarriesTheRecordUid(): void
    {
        self::assertSame('mein-video-42', $this->subject->outputFolderName('Mein Video.mp4', 42));
    }

    #[Test]
    public function accessibilityFolderCarriesItsPrefix(): void
    {
        self::assertSame('ad-mein-video-ad', $this->subject->accessibilityFolderName('ad', 'Mein Video AD.mp4'));
        self::assertSame('sign-dgs', $this->subject->accessibilityFolderName('sign', 'DGS.mp4'));
    }
}
