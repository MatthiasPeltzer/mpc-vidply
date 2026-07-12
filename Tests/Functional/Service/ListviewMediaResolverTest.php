<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\Service;

use Mpc\MpcVidply\Service\ListviewMediaResolver;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ListviewMediaResolverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mpc/mpc-vidply'];

    private ListviewMediaResolver $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = GeneralUtility::makeInstance(ListviewMediaResolver::class);
    }

    #[Test]
    public function resolveRowsReturnsDefaultHeadlinesForDefaultLanguage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ListviewRowTranslations.csv');

        $rows = $this->subject->resolveRows(400, 0, 0);

        self::assertCount(1, $rows);
        self::assertSame('Latest Videos', $rows[0]['headline']);
    }

    #[Test]
    public function resolveRowsOverlaysByL10nParentWhenOnlyDefaultContentElementIsRendered(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ListviewRowConnectedMode.csv');

        // Connected mode: FE renders default CE uid=400 on language 1, overlay row parentid=401.
        $rows = $this->subject->resolveRows(400, 0, 1);

        self::assertCount(1, $rows);
        self::assertSame('English Headline', $rows[0]['headline']);
    }

    #[Test]
    public function resolveRowsOverlaysTranslatedHeadlineWhenChildRowHangsOffTranslatedContentElement(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ListviewRowTranslations.csv');

        $rows = $this->subject->resolveRows(401, 400, 1);

        self::assertCount(1, $rows);
        self::assertSame('Neueste Videos', $rows[0]['headline']);
        self::assertSame(10, (int)($rows[0]['l10n_parent'] ?? 0));
    }
}
