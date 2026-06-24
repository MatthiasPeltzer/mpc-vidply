<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\Service;

use Mpc\MpcVidply\Service\DetailRequestResolver;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DetailRequestResolverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mpc/mpc-vidply'];

    private DetailRequestResolver $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/MediaRecords.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TtContentDetail.csv');
        $this->subject = GeneralUtility::makeInstance(DetailRequestResolver::class);
    }

    /**
     * @param int|string|null $media
     */
    private function buildRequest(int $pageId, $media, int $languageId = 0): ServerRequestInterface
    {
        $request = new ServerRequest('https://example.com/');

        if ($media !== null) {
            $request = $request->withQueryParams(['media' => $media]);
        }

        $pageInformation = new class ($pageId) {
            public function __construct(private readonly int $id) {}
            public function getId(): int
            {
                return $this->id;
            }
        };

        $language = new class ($languageId) {
            public function __construct(private readonly int $languageId) {}
            /** @return array{languageId: int} */
            public function toArray(): array
            {
                return ['languageId' => $this->languageId];
            }
        };

        return $request
            ->withAttribute('frontend.page.information', $pageInformation)
            ->withAttribute('language', $language);
    }

    #[Test]
    public function resolvesMediaByUidOnDetailPage(): void
    {
        $record = $this->subject->resolveFromRequest($this->buildRequest(5, 1));

        self::assertIsArray($record);
        self::assertSame('Clip One', $record['title']);
    }

    #[Test]
    public function resolvesMediaBySlugOnDetailPage(): void
    {
        $record = $this->subject->resolveFromRequest($this->buildRequest(5, 'clip-one'));

        self::assertIsArray($record);
        self::assertSame(1, (int)$record['uid']);
    }

    #[Test]
    public function returnsNullWhenMediaParamMissing(): void
    {
        self::assertNull($this->subject->resolveFromRequest($this->buildRequest(5, null)));
    }

    #[Test]
    public function returnsNullWhenMediaParamEmpty(): void
    {
        self::assertNull($this->subject->resolveFromRequest($this->buildRequest(5, '   ')));
    }

    #[Test]
    public function returnsNullWhenPageDoesNotHostDetailContentElement(): void
    {
        // Page 7 only has a plain text content element.
        self::assertNull($this->subject->resolveFromRequest($this->buildRequest(7, 1)));
    }

    #[Test]
    public function returnsNullWhenPageIdCannotBeResolved(): void
    {
        self::assertNull($this->subject->resolveFromRequest($this->buildRequest(0, 1)));
    }

    #[Test]
    public function memoizesResolvedRecordForRepeatedCalls(): void
    {
        $request = $this->buildRequest(5, 1);

        $first = $this->subject->resolveFromRequest($request);
        $second = $this->subject->resolveFromRequest($request);

        self::assertSame($first, $second);
    }
}
