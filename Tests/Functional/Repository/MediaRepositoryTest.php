<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\Repository;

use Mpc\MpcVidply\Repository\MediaRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class MediaRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mpc/mpc-vidply'];

    private MediaRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/MediaRecords.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ContentMediaMm.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ListviewRowMediaMm.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Categories.csv');
        $this->subject = GeneralUtility::makeInstance(MediaRepository::class);
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<int>
     */
    private function uids(array $records): array
    {
        return array_map(static fn(array $r): int => (int)$r['uid'], $records);
    }

    #[Test]
    public function findByContentUidReturnsRecordsInMmSortingOrder(): void
    {
        $records = $this->subject->findByContentUid(100, 0);

        self::assertSame([2, 1], $this->uids($records));
        self::assertSame(10, (int)$records[0]['sorting']);
        self::assertSame(20, (int)$records[1]['sorting']);
    }

    #[Test]
    public function findByContentUidAppliesLanguageOverlayWithConnectedModeFallback(): void
    {
        // Translated CE (uid 101) inherits the default CE's MM rows (fallback 100).
        $records = $this->subject->findByContentUid(101, 1, 100);

        self::assertSame([2, 11], $this->uids($records));
        self::assertSame('Track Two', $records[0]['title']);
        self::assertSame('Clip Eins', $records[1]['title']);
    }

    #[Test]
    public function findByContentUidFiltersHiddenFutureAndExpiredRecords(): void
    {
        // Content 110 references only a hidden, a future-dated and an expired record.
        self::assertSame([], $this->subject->findByContentUid(110, 0));
    }

    #[Test]
    public function findByContentUidReturnsEmptyForUnknownContent(): void
    {
        self::assertSame([], $this->subject->findByContentUid(999, 0));
    }

    #[Test]
    public function findByUidReturnsDefaultLanguageRecord(): void
    {
        $record = $this->subject->findByUid(1, 0);

        self::assertIsArray($record);
        self::assertSame('Clip One', $record['title']);
    }

    #[Test]
    public function findByUidOverlaysToTranslation(): void
    {
        $record = $this->subject->findByUid(1, 1);

        self::assertIsArray($record);
        self::assertSame('Clip Eins', $record['title']);
        self::assertSame(1, (int)$record['sys_language_uid']);
    }

    #[Test]
    public function findByUidAcceptsTranslatedUid(): void
    {
        $record = $this->subject->findByUid(11, 1);

        self::assertIsArray($record);
        self::assertSame('Clip Eins', $record['title']);
    }

    #[Test]
    public function findByUidReturnsNullForHiddenRecord(): void
    {
        self::assertNull($this->subject->findByUid(3, 0));
    }

    #[Test]
    public function findByUidReturnsNullForFutureRecord(): void
    {
        self::assertNull($this->subject->findByUid(4, 0));
    }

    #[Test]
    public function findByUidReturnsNullForExpiredRecord(): void
    {
        self::assertNull($this->subject->findByUid(5, 0));
    }

    #[Test]
    public function findByUidReturnsNullForInvalidUid(): void
    {
        self::assertNull($this->subject->findByUid(0, 0));
    }

    #[Test]
    public function findBySlugResolvesDefaultLanguageRecord(): void
    {
        $record = $this->subject->findBySlug('clip-one', 0);

        self::assertIsArray($record);
        self::assertSame(1, (int)$record['uid']);
        self::assertSame('Clip One', $record['title']);
    }

    #[Test]
    public function findBySlugReturnsNullForEmptySlug(): void
    {
        self::assertNull($this->subject->findBySlug('   ', 0));
    }

    #[Test]
    public function findBySlugReturnsNullForUnknownSlug(): void
    {
        self::assertNull($this->subject->findBySlug('does-not-exist', 0));
    }

    #[Test]
    public function findByRowUidReturnsManuallySelectedRecordsInOrder(): void
    {
        $records = $this->subject->findByRowUid(200, 0);

        self::assertSame([1, 2], $this->uids($records));
    }

    #[Test]
    public function findByRowUidReturnsEmptyForUnknownRow(): void
    {
        self::assertSame([], $this->subject->findByRowUid(0, 0));
    }

    #[Test]
    public function findByCategoriesReturnsCategorizedRecords(): void
    {
        $records = $this->subject->findByCategories([50], 0);

        self::assertSame([6, 7], $this->uids($records));
    }

    #[Test]
    public function findByCategoriesRespectsLimit(): void
    {
        $records = $this->subject->findByCategories([50], 0, 1);

        self::assertCount(1, $records);
    }

    #[Test]
    public function findByCategoriesReturnsEmptyForNoCategories(): void
    {
        self::assertSame([], $this->subject->findByCategories([], 0));
    }

    #[Test]
    public function findNextInCategoryExcludesTheCurrentRecord(): void
    {
        $records = $this->subject->findNextInCategory(6, 0, 6);

        self::assertSame([7], $this->uids($records));
    }

    #[Test]
    public function findNextInCategoryReturnsEmptyWhenRecordHasNoCategories(): void
    {
        self::assertSame([], $this->subject->findNextInCategory(1, 0));
    }
}
