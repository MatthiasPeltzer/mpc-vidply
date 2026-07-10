<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Backend\Preview;

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
        return self::normalize($item->getRecord());
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalize(mixed $record): array
    {
        if (is_object($record) && method_exists($record, 'toArray')) {
            $record = $record->toArray();
        }

        return is_array($record) ? $record : [];
    }
}
