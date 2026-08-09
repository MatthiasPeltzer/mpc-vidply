<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service\Player;

use Mpc\MpcVidply\Service\Player\LocaleFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocaleFormatterTest extends TestCase
{
    /** 2021-05-18 00:00 UTC — the value TYPO3 stores for a date-only field. */
    private const PUBLISH_DATE = 1621296000;

    private LocaleFormatter $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new LocaleFormatter();
    }

    #[Test]
    public function formatDateReturnsEmptyStringForUnsetDate(): void
    {
        self::assertSame('', $this->subject->formatDate(0, 'en-US'));
    }

    #[Test]
    public function formatDateUsesTheSiteLocale(): void
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            self::markTestSkipped('ext-intl is not available.');
        }

        self::assertSame('18. Mai 2021', $this->subject->formatDate(self::PUBLISH_DATE, 'de-DE'));
        self::assertSame('May 18, 2021', $this->subject->formatDate(self::PUBLISH_DATE, 'en-US'));
    }

    #[Test]
    public function formatDateStaysOnTheStoredDayWithoutALocale(): void
    {
        self::assertSame('2021-05-18', $this->subject->formatDate(self::PUBLISH_DATE, null));
    }

    #[Test]
    public function formatIsoDateIsLocaleIndependent(): void
    {
        self::assertSame('2021-05-18', $this->subject->formatIsoDate(self::PUBLISH_DATE));
        self::assertSame('', $this->subject->formatIsoDate(0));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function fileSizeProvider(): array
    {
        return [
            'unknown size' => [0, ''],
            'bytes stay whole' => [512, '512 B'],
            'kilobytes stay whole' => [2048, '2 KB'],
            'megabytes get one decimal' => [7_759_462, '7.4 MB'],
            'gigabytes get one decimal' => [3_221_225_472, '3.0 GB'],
        ];
    }

    #[Test]
    #[DataProvider('fileSizeProvider')]
    public function formatFileSizeMatchesThePlayersLabels(int $bytes, string $expected): void
    {
        self::assertSame($expected, $this->subject->formatFileSize($bytes, 'en-US'));
    }

    #[Test]
    public function formatFileSizeUsesTheSiteLocale(): void
    {
        if (!class_exists(\NumberFormatter::class)) {
            self::markTestSkipped('ext-intl is not available.');
        }

        self::assertSame('7,4 MB', $this->subject->formatFileSize(7_759_462, 'de-DE'));
    }
}
