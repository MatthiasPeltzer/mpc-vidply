<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\Service;

use Mpc\MpcVidply\Service\MediaPreviewDetailPageResolver;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class MediaPreviewDetailPageResolverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mpc/mpc-vidply'];

    private MediaPreviewDetailPageResolver $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = GeneralUtility::makeInstance(MediaPreviewDetailPageResolver::class);
    }

    #[Test]
    public function resolvesSiteWideDetailPageWhenNoListviewReferenceExists(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TtContentDetail.csv');

        self::assertSame(5, $this->subject->resolveDetailPageUidForMedia(1));
    }

    #[Test]
    public function prefersListviewDetailPageWhenMediaIsManuallySelected(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TtContentDetail.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/MediaPreviewDetailPage.csv');

        self::assertSame(8, $this->subject->resolveDetailPageUidForMedia(1));
    }

    #[Test]
    public function prefersDetailPageInsideMediaStorageFolder(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TtContentDetail.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/MediaPreviewStorageFolder.csv');

        self::assertSame(9051, $this->subject->resolveDetailPageUidForMedia(1, 9050));
    }

    #[Test]
    public function returnsZeroWhenNoDetailPageExists(): void
    {
        self::assertSame(0, $this->subject->resolveDetailPageUidForMedia(1));
    }
}
