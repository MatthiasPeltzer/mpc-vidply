<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Backend\Preview;

use Mpc\MpcVidply\Utility\RecordAwareValueResolver;
use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridColumnItem;

/**
 * Normalises {@see GridColumnItem::getRecord()} across TYPO3 v13 and v14:
 * v14 returns a Record value object exposing toArray(); v13 returns a plain row array.
 */
final class ContentElementPreviewRecord
{
    /**
     * @return array<string, mixed>
     */
    public static function fromGridColumnItem(GridColumnItem $item): array
    {
        return RecordAwareValueResolver::normalizeToArray($item->getRecord());
    }
}
