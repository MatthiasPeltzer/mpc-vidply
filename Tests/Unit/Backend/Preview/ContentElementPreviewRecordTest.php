<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Backend\Preview;

use Mpc\MpcVidply\Backend\Preview\ContentElementPreviewRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridColumnItem;
use TYPO3\CMS\Core\Domain\RecordInterface;

#[CoversClass(ContentElementPreviewRecord::class)]
final class ContentElementPreviewRecordTest extends TestCase
{
    #[Test]
    public function fromGridColumnItemNormalizesRecordInterface(): void
    {
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
