<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service\Player;

use Mpc\MpcVidply\Enums\RenderMode;
use Mpc\MpcVidply\Service\Player\EpisodeListBuilder;
use Mpc\MpcVidply\Service\Player\PlayerTemplateDataBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The episode list builder is only asked for list settings and the lead
 * episode, neither of which touches its FAL collaborators.
 */
#[CoversClass(PlayerTemplateDataBuilder::class)]
final class PlayerTemplateDataBuilderTest extends TestCase
{
    private PlayerTemplateDataBuilder $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new PlayerTemplateDataBuilder(
            (new \ReflectionClass(EpisodeListBuilder::class))->newInstanceWithoutConstructor()
        );
    }

    /**
     * @param array<string, mixed> $singleTrackData
     * @return array<string, mixed>
     */
    private function build(array $singleTrackData = [], array $data = ['uid' => 7]): array
    {
        return $this->subject->build(
            $data,
            ['language' => 'de'],
            [
                'tracks' => [['src' => 'a.mp4', 'type' => 'video/mp4']],
                'records' => [],
                'mediaType' => 'video',
                'hasExternalMedia' => false,
                'hasLocalMedia' => true,
                'externalServiceTypes' => [],
                'isPlaylist' => false,
                'isMixedPlaylist' => false,
            ],
            array_replace([
                'videoUrl' => '',
                'poster' => null,
                'mediaFiles' => [['publicUrl' => 'a.mp4']],
                'sources' => null,
                'captions' => [],
                'chapters' => [],
                'audioDescriptionTracks' => [],
                'signLanguage' => [],
            ], $singleTrackData),
            null,
            null,
            [],
            [
                'playIconUrl' => null,
                'playIconInlineSvg' => null,
                'playButtonPosition' => 'center',
                'useCssIcons' => false,
                'theme' => 'dark',
                'themeSyncEnabled' => false,
            ],
            'video',
            RenderMode::Video,
            ['needsPrivacyLayer' => false, 'needsVidPlay' => true, 'needsPlaylist' => true, 'needsHLS' => true, 'needsDASH' => false]
        );
    }

    #[Test]
    public function buildMapsPipelineResultsToTemplateVariables(): void
    {
        $result = $this->build();

        self::assertSame('vidply-7', $result['uniqueId']);
        self::assertSame('video', $result['renderMode']);
        self::assertSame('de', $result['languageSelection']);
        self::assertTrue($result['needsHLS']);
        self::assertSame('dark', $result['theme']);
        self::assertSame([['publicUrl' => 'a.mp4']], $result['mediaFiles']);
        self::assertArrayNotHasKey('sources', $result);
        self::assertArrayNotHasKey('downloadUrl', $result);
        self::assertNull($result['audioDescriptionTracksJson']);
        self::assertNull($result['signLanguageJson']);
    }

    #[Test]
    public function sourcesReplaceMediaFiles(): void
    {
        $sources = [['src' => 'a.mp4', 'type' => 'video/mp4'], ['src' => 'a.webm', 'type' => 'video/webm']];

        $result = $this->build(['sources' => $sources]);

        self::assertSame($sources, $result['sources']);
        self::assertSame([], $result['mediaFiles']);
    }

    #[Test]
    public function downloadUrlIsPassedOnWhenPresent(): void
    {
        self::assertSame('/fileadmin/a.mp4', $this->build(['downloadUrl' => '/fileadmin/a.mp4'])['downloadUrl']);
    }

    #[Test]
    public function accessibilityTracksAreJsonEncodedSafelyForScriptTags(): void
    {
        $result = $this->build([
            'audioDescriptionTracks' => [['src' => '/ad.mp4?a=1&b=</script>', 'lang' => 'de']],
            'signLanguage' => [['src' => '/sl.mp4', 'lang' => 'de'], ['src' => "/sl'2.mp4", 'lang' => 'en']],
        ]);

        self::assertIsString($result['audioDescriptionTracksJson']);
        self::assertStringNotContainsString('</script>', $result['audioDescriptionTracksJson']);
        self::assertStringContainsString('\u003C\/script\u003E', $result['audioDescriptionTracksJson']);
        self::assertStringContainsString('\u0026', $result['audioDescriptionJson']);
        self::assertStringContainsString('\u0027', $result['signLanguageJson']);
        self::assertSame('/ad.mp4?a=1&b=</script>', $result['audioDescriptionDefaultSrc']);
        self::assertSame('/sl.mp4', $result['signLanguageDefaultSrc']);
        self::assertTrue($result['signLanguageHasMultiple']);
    }

    #[Test]
    public function safeJsonEncodeEscapesHtmlSignificantCharacters(): void
    {
        self::assertSame(
            '{"a":"\u003C\u003E\u0026\u0027\u0022"}',
            $this->subject->safeJsonEncode(['a' => '<>&\'"'])
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function headlineLevelProvider(): array
    {
        return [
            'no header' => [['header' => '', 'header_layout' => 3], 2],
            'hidden header layout' => [['header' => 'Title', 'header_layout' => 100], 2],
            'default layout renders h2, so h3' => [['header' => 'Title', 'header_layout' => 0], 3],
            'h1 header' => [['header' => 'Title', 'header_layout' => 1], 2],
            'h4 header' => [['header' => 'Title', 'header_layout' => 4], 5],
            'h6 header is capped at h6' => [['header' => 'Title', 'header_layout' => 6], 6],
            'visually hidden h2' => [['header' => 'Title', 'header_layout' => 8], 3],
            'unknown layout' => [['header' => 'Title', 'header_layout' => 42], 3],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('headlineLevelProvider')]
    public function privacyHeadlineSitsOneLevelBelowTheContentHeader(array $data, int $expected): void
    {
        self::assertSame($expected, $this->subject->resolvePrivacyHeadlineLevel($data));
    }
}
