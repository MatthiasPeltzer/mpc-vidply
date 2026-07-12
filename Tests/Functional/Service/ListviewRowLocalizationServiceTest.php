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
        $count = (int)$connection
            ->count('uid', 'tx_mpcvidply_listview_row', ['l10n_parent' => 10, 'sys_language_uid' => 1])
            ->fetchOne();

        self::assertSame(1, $count);
    }
}
