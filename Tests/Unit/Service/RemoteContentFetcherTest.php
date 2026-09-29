<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Mpc\MpcVidply\Service\RemoteContentFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\RequestFactory;

#[CoversClass(RemoteContentFetcher::class)]
final class RemoteContentFetcherTest extends TestCase
{
    private const JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

    private static function jpeg(): string
    {
        return (string)base64_decode(self::JPEG_BASE64, true);
    }

    #[Test]
    public function detectImageExtensionRecognizesPosterFormats(): void
    {
        self::assertSame('jpg', RemoteContentFetcher::detectImageExtension(self::jpeg()));
        self::assertSame('png', RemoteContentFetcher::detectImageExtension(self::PNG));
    }

    #[Test]
    public function detectImageExtensionRejectsSvgAndHtml(): void
    {
        self::assertNull(RemoteContentFetcher::detectImageExtension('<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
        self::assertNull(RemoteContentFetcher::detectImageExtension('<html><body>hi</body></html>'));
        self::assertNull(RemoteContentFetcher::detectImageExtension(''));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function thumbnailHostProvider(): array
    {
        return [
            'youtube' => ['i.ytimg.com', true],
            'vimeo cdn' => ['i.vimeocdn.com', true],
            'vumbnail' => ['vumbnail.com', true],
            'soundcloud cdn' => ['i1.sndcdn.com', true],
            'metadata ip' => ['169.254.169.254', false],
            'localhost' => ['localhost', false],
            'suffix trick' => ['evilsndcdn.com', false],
        ];
    }

    #[Test]
    #[DataProvider('thumbnailHostProvider')]
    public function isThumbnailHostUsesAllowList(string $host, bool $expected): void
    {
        self::assertSame($expected, RemoteContentFetcher::isThumbnailHost($host));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedThumbnailUrlProvider(): array
    {
        return [
            'local file' => ['/etc/passwd'],
            'file scheme' => ['file:///etc/passwd'],
            'plain http' => ['http://i.ytimg.com/vi/x/hqdefault.jpg'],
            'foreign host' => ['https://169.254.169.254/latest/meta-data/'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedThumbnailUrlProvider')]
    public function fetchThumbnailNeverRequestsUrlsOutsideTheAllowList(string $url): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::never())->method('request');

        self::assertNull((new RemoteContentFetcher($requestFactory))->fetchThumbnail($url));
    }

    #[Test]
    public function fetchThumbnailDisablesRedirectsAndChecksTheImageType(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::once())
            ->method('request')
            ->with(
                'https://i.ytimg.com/vi/abc/hqdefault.jpg',
                'GET',
                self::callback(static fn (array $options): bool => $options['allow_redirects'] === false
                    && $options['timeout'] > 0
                    && $options['connect_timeout'] > 0)
            )
            ->willReturn(new Response(200, [], Utils::streamFor(self::jpeg())));

        self::assertSame(
            ['binary' => self::jpeg(), 'extension' => 'jpg'],
            (new RemoteContentFetcher($requestFactory))->fetchThumbnail('https://i.ytimg.com/vi/abc/hqdefault.jpg')
        );
    }

    #[Test]
    public function fetchRejectsBodiesAboveTheSizeLimit(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturn(new Response(200, [], Utils::streamFor(str_repeat('a', 2048))));

        self::assertNull((new RemoteContentFetcher($requestFactory))->fetch('https://example.com/page', 1024));
    }

    #[Test]
    public function fetchRejectsOversizedContentLengthUpFront(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturn(new Response(200, ['Content-Length' => '999999'], Utils::streamFor('x')));

        self::assertNull((new RemoteContentFetcher($requestFactory))->fetch('https://example.com/page', 1024));
    }

    #[Test]
    public function fetchReturnsNullForNonOkStatus(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturn(new Response(302, ['Location' => 'http://127.0.0.1/']));

        self::assertNull((new RemoteContentFetcher($requestFactory))->fetch('https://example.com/page', 1024));
    }
}
