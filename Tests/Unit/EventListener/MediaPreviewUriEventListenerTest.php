<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\EventListener;

use Mpc\MpcVidply\EventListener\MediaPreviewUriEventListener;
use Mpc\MpcVidply\Service\MediaPreviewDetailPageResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Routing\Event\BeforePagePreviewUriGeneratedEvent;
use TYPO3\CMS\Core\Context\Context;

#[CoversClass(MediaPreviewUriEventListener::class)]
final class MediaPreviewUriEventListenerTest extends TestCase
{
    private function createEvent(array $queryParameters = []): BeforePagePreviewUriGeneratedEvent
    {
        return new BeforePagePreviewUriGeneratedEvent(
            1,
            0,
            [],
            '',
            $queryParameters,
            new Context(),
            []
        );
    }

    #[Test]
    public function ignoresPreviewRequestsWithoutMediaParameter(): void
    {
        $event = $this->createEvent();

        (new MediaPreviewUriEventListener(new MediaPreviewDetailPageResolver()))($event);

        self::assertSame(1, $event->getPageId());
        self::assertSame([], $event->getAdditionalQueryParameters());
    }

    #[Test]
    public function ignoresNonNumericMediaParameter(): void
    {
        $event = $this->createEvent(['media' => 'clip-one']);

        (new MediaPreviewUriEventListener(new MediaPreviewDetailPageResolver()))($event);

        self::assertSame(['media' => 'clip-one'], $event->getAdditionalQueryParameters());
    }
}
