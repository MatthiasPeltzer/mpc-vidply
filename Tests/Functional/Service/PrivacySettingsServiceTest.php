<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\Service;

use Mpc\MpcVidply\Service\PrivacySettingsService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PrivacySettingsServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mpc/mpc-vidply'];

    private PrivacySettingsService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = GeneralUtility::makeInstance(PrivacySettingsService::class);
    }

    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(PrivacySettingsService::class, $method))->invoke($this->subject, ...$args);
    }

    #[Test]
    public function returnsConfiguredDatabaseValues(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PrivacySettings.csv');

        $settings = $this->subject->getSettingsForService('youtube', 0);

        self::assertSame('Watch on YouTube', $settings['headline']);
        self::assertSame('Custom YouTube intro', $settings['intro_text']);
        self::assertSame('Custom outro', $settings['outro_text']);
        self::assertSame('https://example.com/yt-privacy', $settings['policy_link']);
        self::assertSame('YouTube policy', $settings['link_text']);
        self::assertSame('Load video', $settings['button_label']);
    }

    #[Test]
    public function fillsEmptyFieldsWithDefaults(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PrivacySettings.csv');

        // The vimeo_* columns are empty in the fixture, so defaults must kick in.
        $settings = $this->subject->getSettingsForService('vimeo', 0);

        self::assertSame('', $settings['headline']);
        self::assertNotSame('', $settings['intro_text'], 'Empty intro text should fall back to the language label.');
        self::assertSame('https://vimeo.com/privacy', $settings['policy_link']);
        self::assertNotSame('', $settings['link_text']);
    }

    #[Test]
    public function fallsBackToDefaultsWhenNoSettingsRecordExists(): void
    {
        $settings = $this->subject->getSettingsForService('soundcloud', 0);

        self::assertSame('', $settings['headline']);
        self::assertSame('https://soundcloud.com/pages/privacy', $settings['policy_link']);
        self::assertNotSame('', $settings['intro_text']);
    }

    #[Test]
    public function getAllSettingsReturnsNullWhenTableEmpty(): void
    {
        self::assertNull($this->subject->getAllSettings(0));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function httpUrlProvider(): array
    {
        return [
            'https url' => ['https://example.com/p', true],
            'http url' => ['http://example.com/p', true],
            'ftp rejected' => ['ftp://example.com/p', false],
            'relative rejected' => ['/relative/path', false],
            'empty rejected' => ['', false],
            'javascript rejected' => ['javascript:alert(1)', false],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('httpUrlProvider')]
    public function isHttpUrlValidatesScheme(string $url, bool $expected): void
    {
        self::assertSame($expected, $this->invokePrivate('isHttpUrl', $url));
    }

    #[Test]
    public function sanitizePrivacyTextStripsControlCharsAndClampsLength(): void
    {
        $withControlChars = "Hello\x00\x07 World\x7f";
        self::assertSame('Hello World', $this->invokePrivate('sanitizePrivacyText', $withControlChars, 255));

        $long = str_repeat('a', 300);
        $clamped = $this->invokePrivate('sanitizePrivacyText', $long, 255);
        self::assertSame(255, mb_strlen($clamped));

        self::assertSame('trimmed', $this->invokePrivate('sanitizePrivacyText', '  trimmed  ', 255));
    }
}
