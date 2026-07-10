<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Backend\Preview;

use Mpc\MpcVidply\Backend\Preview\ContentElementPreviewRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridColumnItem;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Information\Typo3Version;

#[CoversClass(ContentElementPreviewRecord::class)]
final class ContentElementPreviewRecordTest extends TestCase
{
    #[Test]
    public function fromGridColumnItemNormalizesArrayRecordOnTypo3V13(): void
    {
        if ((new Typo3Version())->getMajorVersion() >= 14) {
            self::markTestSkipped('GridColumnItem::getRecord() returns RecordInterface on TYPO3 v14+.');
        }

        $row = ['uid' => 10, 'CType' => 'mpc_vidply'];

        $item = $this->createMock(GridColumnItem::class);
        $item->method('getRecord')->willReturn($row);

        self::assertSame($row, ContentElementPreviewRecord::fromGridColumnItem($item));
    }

    #[Test]
    public function fromGridColumnItemNormalizesRecordInterfaceOnTypo3V14(): void
    {
        if ((new Typo3Version())->getMajorVersion() < 14) {
            self::markTestSkipped('GridColumnItem::getRecord() returns array on TYPO3 v13.');
        }

        $record = $this->createMock(RecordInterface::class);
        $record->method('toArray')->willReturn(['uid' => 10, 'CType' => 'mpc_vidply']);

        $item = $this->createMock(GridColumnItem::class);
        $item->method('getRecord')->willReturn($record);

        self::assertSame(
            ['uid' => 10, 'CType' => 'mpc_vidply'],
            ContentElementPreviewRecord::fromGridColumnItem($item)
        );
    }
}
