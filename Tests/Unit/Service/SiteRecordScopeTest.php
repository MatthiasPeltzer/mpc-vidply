<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service;

use Mpc\MpcVidply\Service\SiteRecordScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

#[CoversClass(SiteRecordScope::class)]
final class SiteRecordScopeTest extends TestCase
{
    /**
     * Pages 10–19 belong to site "a", 20–29 to site "b", everything else to no site.
     */
    private function createSubject(): SiteRecordScope
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturnCallback(
            static function (int $pageId): Site {
                return match (intdiv($pageId, 10)) {
                    1 => new Site('a', 10, []),
                    2 => new Site('b', 20, []),
                    default => throw new SiteNotFoundException('none', 1),
                };
            }
        );

        return new SiteRecordScope($siteFinder);
    }

    #[Test]
    public function recordOfAnotherSiteIsRejected(): void
    {
        $subject = $this->createSubject();
        $siteA = new Site('a', 10, []);

        self::assertNull($subject->scopeRecord(['uid' => 1, 'pid' => 21], $siteA));
        self::assertSame(['uid' => 1, 'pid' => 11], $subject->scopeRecord(['uid' => 1, 'pid' => 11], $siteA));
    }

    #[Test]
    public function recordOutsideEverySiteIsShared(): void
    {
        self::assertTrue($this->createSubject()->isPageInSite(5, new Site('a', 10, [])));
    }

    #[Test]
    public function withoutARealSiteNothingIsScoped(): void
    {
        $subject = $this->createSubject();

        self::assertTrue($subject->isPageInSite(21, null));
        self::assertTrue($subject->isPageInSite(21, new NullSite()));
    }

    #[Test]
    public function filterRecordsKeepsOwnAndSharedRecords(): void
    {
        $records = [['uid' => 1, 'pid' => 11], ['uid' => 2, 'pid' => 21], ['uid' => 3, 'pid' => 3]];

        self::assertSame(
            [['uid' => 1, 'pid' => 11], ['uid' => 3, 'pid' => 3]],
            $this->createSubject()->filterRecords($records, new Site('a', 10, []))
        );
    }

    #[Test]
    public function findFirstPageOfSitePicksThePageOfTheGivenSite(): void
    {
        $subject = $this->createSubject();

        self::assertSame(22, $subject->findFirstPageOfSite([3, 12, 22], 'b'));
        self::assertSame(0, $subject->findFirstPageOfSite([3, 12], 'b'));
        self::assertSame(3, $subject->findFirstPageOfSite([3, 12], null));
    }
}
