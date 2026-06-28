<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service;

use Mpc\MpcVidply\Service\MediaObjectJsonLdBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

final class MediaObjectJsonLdBuilderTest extends TestCase
{
    private MediaObjectJsonLdBuilder $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new MediaObjectJsonLdBuilder();
    }

    #[Test]
    public function buildGraphNodeReturnsNullWhenStructuredDataDisabled(): void
    {
        $request = $this->requestWithStructuredDataEnabled(false);

        self::assertNull($this->subject->buildGraphNode(
            ['title' => 'Clip'],
            $request,
            null,
            'https://example.com/video/clip',
            'https://example.com/poster.jpg',
        ));
    }

    #[Test]
    public function buildGraphNodeEmitsVideoObjectWithGoogleRelevantFields(): void
    {
        $node = $this->subject->buildGraphNode(
            [
                'title' => 'Accessible clip',
                'description' => 'Short teaser',
                'duration' => 125,
                'crdate' => 1_704_067_200,
                'media_type' => 'video',
            ],
            $this->requestWithStructuredDataEnabled(true),
            [
                'downloadUrl' => '/fileadmin/clip.mp4',
                'tracks' => [
                    ['src' => '/fileadmin/clip.mp4', 'type' => 'video/mp4'],
                ],
            ],
            '/videos/accessible-clip',
            '/fileadmin/poster.jpg',
        );

        self::assertIsArray($node);
        self::assertSame('VideoObject', $node['@type']);
        self::assertSame('https://example.com/videos/accessible-clip#media', $node['@id']);
        self::assertSame('Accessible clip', $node['name']);
        self::assertSame('Short teaser', $node['description']);
        self::assertSame('PT2M5S', $node['duration']);
        self::assertSame('2024-01-01T00:00:00Z', $node['uploadDate']);
        self::assertSame('https://example.com/videos/accessible-clip', $node['url']);
        self::assertSame('https://example.com/fileadmin/poster.jpg', $node['thumbnailUrl']);
        self::assertSame('https://example.com/fileadmin/clip.mp4', $node['contentUrl']);
        self::assertSame('https://example.com/videos/accessible-clip', $node['embedUrl']);
    }

    #[Test]
    public function buildGraphNodeUsesLongDescriptionFallbackAndAudioObject(): void
    {
        $node = $this->subject->buildGraphNode(
            [
                'title' => 'Podcast episode',
                'long_description' => '<p>Longer episode summary.</p>',
                'duration' => 61,
                'crdate' => 1_704_067_200,
                'media_type' => 'audio',
            ],
            $this->requestWithStructuredDataEnabled(true),
            [
                'tracks' => [
                    ['src' => 'https://cdn.example.com/episode.mp3', 'type' => 'audio/mpeg'],
                ],
            ],
            'https://example.com/audio/episode',
            'https://example.com/poster.jpg',
        );

        self::assertSame('AudioObject', $node['@type']);
        self::assertSame('Longer episode summary.', $node['description']);
        self::assertSame('https://cdn.example.com/episode.mp3', $node['contentUrl']);
    }

    #[Test]
    #[DataProvider('youtubeUrlProvider')]
    public function resolveMediaUrlsBuildsYouTubeWatchAndEmbedUrls(string $src): void
    {
        $urls = $this->subject->resolveMediaUrls(
            ['tracks' => [['src' => $src, 'type' => 'youtube']]],
            'youtube',
            'https://example.com/detail',
        );

        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $urls['contentUrl']);
        self::assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ', $urls['embedUrl']);
    }

    /** @return list<array{string}> */
    public static function youtubeUrlProvider(): array
    {
        return [
            ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            ['https://youtu.be/dQw4w9WgXcQ'],
            ['dQw4w9WgXcQ'],
        ];
    }

    #[Test]
    public function resolveMediaUrlsBuildsVimeoWatchAndEmbedUrls(): void
    {
        $urls = $this->subject->resolveMediaUrls(
            ['tracks' => [['src' => 'https://vimeo.com/123456789', 'type' => 'vimeo']]],
            'vimeo',
            'https://example.com/detail',
        );

        self::assertSame('https://vimeo.com/123456789', $urls['contentUrl']);
        self::assertSame('https://player.vimeo.com/video/123456789', $urls['embedUrl']);
    }

    #[Test]
    public function resolveMediaUrlsPrefersProgressiveSourceForLocalVideo(): void
    {
        $urls = $this->subject->resolveMediaUrls(
            [
                'tracks' => [[
                    'src' => 'https://cdn.example.com/master.m3u8',
                    'type' => 'application/x-mpegurl',
                    'sources' => [
                        ['src' => 'https://cdn.example.com/master.m3u8', 'type' => 'application/x-mpegurl'],
                        ['src' => 'https://cdn.example.com/clip.webm', 'type' => 'video/webm'],
                    ],
                ]],
            ],
            'video',
            'https://example.com/detail',
        );

        self::assertSame('https://cdn.example.com/clip.webm', $urls['contentUrl']);
        self::assertSame('https://example.com/detail', $urls['embedUrl']);
    }

    #[Test]
    public function buildItemListGraphNodeBuildsListWithEmbeddedVideoObjects(): void
    {
        $request = $this->requestWithStructuredDataEnabled(true);
        $list = $this->subject->buildItemListGraphNode(
            $request,
            'https://example.com/videos',
            'Videos',
            [
                [
                    'media' => [
                        'uid' => 1,
                        'title' => 'Clip A',
                        'media_type' => 'video',
                        'duration' => 30,
                        'crdate' => 1_704_067_200,
                    ],
                    'vidply' => [
                        'tracks' => [
                            ['src' => 'https://cdn.example.com/a.mp4', 'type' => 'video/mp4'],
                        ],
                    ],
                    'pageUrl' => 'https://example.com/videos',
                    'itemUrl' => 'https://example.com/videos/a',
                    'posterUrl' => 'https://example.com/a.jpg',
                ],
                [
                    'media' => [
                        'uid' => 2,
                        'title' => 'Clip B',
                        'media_type' => 'video',
                        'duration' => 45,
                        'crdate' => 1_704_067_200,
                    ],
                    'vidply' => [
                        'tracks' => [
                            ['src' => 'https://cdn.example.com/b.mp4', 'type' => 'video/mp4'],
                        ],
                    ],
                    'pageUrl' => 'https://example.com/videos',
                    'itemUrl' => 'https://example.com/videos/b',
                    'posterUrl' => null,
                ],
            ],
        );

        self::assertIsArray($list);
        self::assertSame('ItemList', $list['@type']);
        self::assertSame('https://example.com/videos#media-list', $list['@id']);
        self::assertSame('Videos', $list['name']);
        self::assertSame(2, $list['numberOfItems']);
        self::assertCount(2, $list['itemListElement']);
        self::assertSame('ListItem', $list['itemListElement'][0]['@type']);
        self::assertSame(1, $list['itemListElement'][0]['position']);
        self::assertSame('VideoObject', $list['itemListElement'][0]['item']['@type']);
        self::assertSame('https://example.com/videos#media-1', $list['itemListElement'][0]['item']['@id']);
        self::assertSame('https://example.com/videos/a', $list['itemListElement'][0]['item']['url']);
    }

    #[Test]
    public function resolveMediaUrlsOmitsContentUrlForHlsStreamWithoutProgressiveSource(): void
    {
        $urls = $this->subject->resolveMediaUrls(
            [
                'tracks' => [[
                    'src' => 'https://cdn.example.com/live/master.m3u8',
                    'type' => 'application/x-mpegurl',
                ]],
            ],
            'video',
            'https://example.com/detail',
        );

        self::assertNull($urls['contentUrl']);
        self::assertSame('https://example.com/detail', $urls['embedUrl']);
    }

    #[Test]
    public function encodeStandaloneGraphNodeKeepsUnicodeReadableAndEscapesHtml(): void
    {
        $json = $this->subject->encodeStandaloneGraphNode([
            '@type' => 'VideoObject',
            'name' => 'Über <b>Tests</b>',
        ]);

        self::assertIsString($json);
        self::assertStringContainsString('"@context":"https://schema.org"', $json);
        self::assertStringContainsString('Über', $json);
        self::assertStringNotContainsString('<b>', $json);
    }

    private function requestWithStructuredDataEnabled(bool $enabled): ServerRequestInterface
    {
        $settings = SiteSettings::createFromSettingsTree(['structuredDataEnabled' => $enabled]);

        $site = $this->createMock(Site::class);
        $site->method('getSettings')->willReturn($settings);

        // Build NormalizedParams directly (siteUrl https://example.com/) so the unit
        // test does not depend on a fully initialised TYPO3 Environment.
        $normalizedParams = new NormalizedParams(
            [
                'HTTP_HOST' => 'example.com',
                'HTTPS' => 'on',
                'SCRIPT_NAME' => '/index.php',
                'SCRIPT_FILENAME' => '/var/www/html/public/index.php',
            ],
            [],
            '/var/www/html/public/index.php',
            '/var/www/html/public'
        );

        return (new ServerRequest('https://example.com/'))
            ->withAttribute('site', $site)
            ->withAttribute('normalizedParams', $normalizedParams);
    }
}
