<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\Service;

use Mpc\MpcVidply\Service\CategoryTitleResolver;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class CategoryTitleResolverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mpc/mpc-vidply'];

    private CategoryTitleResolver $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = GeneralUtility::makeInstance(CategoryTitleResolver::class);
    }

    #[Test]
    public function returnsDefaultTitlesForDefaultLanguage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/CategoryTranslations.csv');

        $result = $this->subject->localizeCategories([
            ['uid' => 50, 'title' => 'Documentaries'],
        ], 0);

        self::assertSame([
            ['uid' => 50, 'title' => 'Documentaries'],
        ], $result);
    }

    #[Test]
    public function overlaysTranslatedCategoryTitles(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/CategoryTranslations.csv');

        $result = $this->subject->localizeCategories([
            ['uid' => 50, 'title' => 'Documentaries'],
        ], 1);

        self::assertSame([
            ['uid' => 51, 'title' => 'Dokumentationen'],
        ], $result);
    }

    #[Test]
    public function fallsBackToDefaultTitleWhenTranslationIsMissing(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/CategoryTranslations.csv');

        $result = $this->subject->localizeCategories([
            ['uid' => 60, 'title' => 'Music'],
        ], 1);

        self::assertSame([
            ['uid' => 60, 'title' => 'Music'],
        ], $result);
    }
}
