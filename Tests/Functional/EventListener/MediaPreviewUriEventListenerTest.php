<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\EventListener;

use Mpc\MpcVidply\EventListener\MediaPreviewUriEventListener;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Event\BeforePagePreviewUriGeneratedEvent;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Routing\UnableToLinkToPageException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class MediaPreviewUriEventListenerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mpc/mpc-vidply'];

    #[Test]
    public function rewritesPreviewParametersToDetailPageWithSlug(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/MediaRecords.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TtContentDetail.csv');

        $event = new BeforePagePreviewUriGeneratedEvent(
            1,
            0,
            [],
            '',
            ['media' => '1'],
            new Context(),
            []
        );

        GeneralUtility::makeInstance(MediaPreviewUriEventListener::class)($event);

        self::assertSame(5, $event->getPageId());
        self::assertSame(['media' => 'clip-one'], $event->getAdditionalQueryParameters());
        self::assertSame('', $event->getSection());
    }

    #[Test]
    public function throwsWhenNoDetailPageExists(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/MediaRecords.csv');

        $event = new BeforePagePreviewUriGeneratedEvent(
            1,
            0,
            [],
            '',
            ['media' => '1'],
            new Context(),
            []
        );

        $this->expectException(UnableToLinkToPageException::class);

        GeneralUtility::makeInstance(MediaPreviewUriEventListener::class)($event);
    }

    #[Test]
    public function keepsTranslatedMediaLanguageInPreviewParameters(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/MediaRecords.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/TtContentDetail.csv');

        $translatedUid = (int)(BackendUtility::getRecord('tx_mpcvidply_media', 11)['uid'] ?? 0);
        self::assertSame(11, $translatedUid);

        $event = new BeforePagePreviewUriGeneratedEvent(
            1,
            0,
            [],
            '',
            ['media' => '11', '_language' => 1],
            new Context(),
            []
        );

        GeneralUtility::makeInstance(MediaPreviewUriEventListener::class)($event);

        self::assertSame(5, $event->getPageId());
        self::assertSame(1, $event->getLanguageId());
        self::assertSame(
            ['media' => 'clip-eins', '_language' => 1],
            $event->getAdditionalQueryParameters()
        );
    }
}
