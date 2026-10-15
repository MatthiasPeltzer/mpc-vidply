<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\Service;

use Mpc\MpcVidply\Service\MediaPosterResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class MediaPosterResolverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mpc/mpc-vidply'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/MediaRecords.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/MediaPosters.csv');
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function posterCases(): iterable
    {
        yield 'oldest record wins when several records use the file' => [100, 200];
        yield 'default-language record wins over a translation' => [101, 206];
        yield 'deleted poster reference is ignored' => [102, 0];
        yield 'deleted media reference is ignored' => [103, 0];
        yield 'file referenced by another table has no poster' => [104, 0];
        yield 'first poster by sorting wins' => [105, 231];
        yield 'unreferenced file has no poster' => [999, 0];
        yield 'invalid file uid has no poster' => [0, 0];
    }

    #[Test]
    #[DataProvider('posterCases')]
    public function findsPosterOfMediaRecordUsingTheFile(int $mediaFileUid, int $expectedPosterUid): void
    {
        $subject = $this->get(MediaPosterResolver::class);

        self::assertSame($expectedPosterUid, $subject->findPosterFileUid($mediaFileUid));
    }

    /**
     * @return iterable<string, array{list<int>, list<int>}>
     */
    public static function containerCases(): iterable
    {
        yield 'file shared by two records is listed once' => [[1, 2], [100]];
        yield 'records with different containers' => [[6, 7], [101, 102]];
        yield 'deleted media reference still counts' => [[5], [103]];
        yield 'YouTube file is no VidPly container' => [[3], []];
        yield 'reference from another table is ignored' => [[4], []];
        yield 'no records' => [[], []];
    }

    /**
     * @param list<int> $recordUids
     * @param list<int> $expectedFileUids
     */
    #[Test]
    #[DataProvider('containerCases')]
    public function findsContainerFilesOfMediaRecords(array $recordUids, array $expectedFileUids): void
    {
        $subject = $this->get(MediaPosterResolver::class);

        self::assertSame($expectedFileUids, $subject->findContainerFileUids($recordUids));
    }
}
