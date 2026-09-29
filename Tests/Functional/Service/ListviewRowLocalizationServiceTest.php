<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\Service;

use Mpc\MpcVidply\Service\ListviewRowLocalizationService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ListviewRowLocalizationServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mpc/mpc-vidply'];

    private ListviewRowLocalizationService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = GeneralUtility::makeInstance(ListviewRowLocalizationService::class);
    }

    #[Test]
    public function createsLocalizedRowsForTranslatedContentElement(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ListviewRowLocalization.csv');

        $this->subject->ensureLocalizedRowsForTranslation(400, 401, 1);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mpcvidply_listview_row');
        $localized = $connection
            ->select(['*'], 'tx_mpcvidply_listview_row', ['l10n_parent' => 10, 'sys_language_uid' => 1])
            ->fetchAssociative();

        self::assertIsArray($localized);
        self::assertSame(401, (int)($localized['parentid'] ?? 0));
        self::assertSame('Latest Videos', (string)($localized['headline'] ?? ''));
        self::assertSame(10, (int)($localized['l10n_parent'] ?? 0));
    }

    #[Test]
    public function ensureLocalizedRowsForAllTranslationsCreatesMissingOverlays(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ListviewRowLocalization.csv');

        $this->subject->ensureLocalizedRowsForAllTranslations(400);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mpcvidply_listview_row');
        $count = $connection->count(
            'uid',
            'tx_mpcvidply_listview_row',
            ['l10n_parent' => 10, 'sys_language_uid' => 1]
        );

        self::assertSame(1, $count);
    }

    #[Test]
    public function hiddenLocalizedRowIsNotDuplicatedOnRepeatedSaves(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ListviewRowLocalization.csv');
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mpcvidply_listview_row');

        $this->subject->ensureLocalizedRowsForTranslation(400, 401, 1);
        $connection->update('tx_mpcvidply_listview_row', ['hidden' => 1], ['l10n_parent' => 10, 'sys_language_uid' => 1]);
        $connection->update('tx_mpcvidply_listview_row', ['hidden' => 1], ['uid' => 10]);

        $this->subject->ensureLocalizedRowsForTranslation(400, 401, 1);
        $this->subject->ensureLocalizedRowsForTranslation(400, 401, 1);

        self::assertCount(1, $this->fetchLocalizedRowsIncludingHidden());
    }

    #[Test]
    public function hiddenDefaultRowPassesItsVisibilityToTheTranslation(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ListviewRowLocalization.csv');
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mpcvidply_listview_row');

        $this->subject->ensureLocalizedRowsForTranslation(400, 401, 1);
        $connection->update('tx_mpcvidply_listview_row', ['hidden' => 1], ['uid' => 10]);
        $this->subject->ensureLocalizedRowsForTranslation(400, 401, 1);

        $localized = $this->fetchLocalizedRowsIncludingHidden();

        self::assertCount(1, $localized);
        self::assertSame(1, (int)$localized[0]['hidden']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchLocalizedRowsIncludingHidden(): array
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_mpcvidply_listview_row');
        $qb->getRestrictions()->removeAll();

        return $qb->select('*')
            ->from('tx_mpcvidply_listview_row')
            ->where(
                $qb->expr()->eq('l10n_parent', 10),
                $qb->expr()->eq('sys_language_uid', 1),
                $qb->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
