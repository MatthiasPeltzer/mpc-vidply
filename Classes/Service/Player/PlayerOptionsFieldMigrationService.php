<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Player;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Moves resume playback and show-track-info from the player options bitmask
 * into dedicated tt_content columns, and remaps legacy keyboard/auto-advance
 * bits (64/256) to FormEngine positional bits (32/64).
 */
final class PlayerOptionsFieldMigrationService
{
    private const BITMASK_RESUME_PLAYBACK = 128 | 512;
    /** @deprecated Legacy keyboard bit before FormEngine positional alignment */
    private const LEGACY_OPT_KEYBOARD = 64;
    /** @deprecated Legacy auto-advance bit before FormEngine positional alignment */
    private const LEGACY_OPT_AUTO_ADVANCE = 256;

    /**
     * @return array{showTrackInfo: int, resumePlayback: int, options: int}
     */
    public function migrateRecord(
        int $options,
        int $showTrackInfo = 0,
        int $resumePlayback = 0
    ): array {
        $showTrackInfoFromBitmask = false;
        if (!$showTrackInfo) {
            if (($options & 1024) !== 0) {
                $showTrackInfo = 1;
                $showTrackInfoFromBitmask = true;
            } elseif (($options & 32) !== 0 && ($options & self::LEGACY_OPT_AUTO_ADVANCE) !== 0) {
                // Bit 32 doubled as show-track-info in records that still use legacy auto-advance (256).
                $showTrackInfo = 1;
                $showTrackInfoFromBitmask = true;
            }
        }
        if (!$resumePlayback) {
            $resumePlayback = ($options & self::BITMASK_RESUME_PLAYBACK) !== 0 ? 1 : 0;
        }

        $options &= ~self::BITMASK_RESUME_PLAYBACK;
        $options &= ~1024;
        if ($showTrackInfoFromBitmask) {
            $options &= ~32;
        }

        $options = self::remapLegacyKeyboardAndAutoAdvanceBits($options);

        return [
            'showTrackInfo' => $showTrackInfo,
            'resumePlayback' => $resumePlayback,
            'options' => $options,
        ];
    }

    private static function remapLegacyKeyboardAndAutoAdvanceBits(int $options): int
    {
        if (($options & self::LEGACY_OPT_AUTO_ADVANCE) !== 0) {
            $legacyKeyboard = ($options & self::LEGACY_OPT_KEYBOARD) !== 0;
            $options &= ~(self::LEGACY_OPT_KEYBOARD | self::LEGACY_OPT_AUTO_ADVANCE);
            if ($legacyKeyboard) {
                $options |= 32;
            }
            $options |= 64;

            return $options;
        }

        if (($options & self::LEGACY_OPT_KEYBOARD) !== 0 && ($options & 32) === 0) {
            $options &= ~self::LEGACY_OPT_KEYBOARD;
            $options |= 32;
        }

        return $options;
    }

    /**
     * @return list<array{
     *     uid: int,
     *     options: int,
     *     showTrackInfo: int,
     *     resumePlayback: int,
     *     newOptions: int,
     *     newShowTrackInfo: int,
     *     newResumePlayback: int
     * }>
     */
    public function findRecordsNeedingMigration(): array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content');
        $queryBuilder = $connection->createQueryBuilder();
        $rows = $queryBuilder
            ->select(
                'uid',
                'tx_mpcvidply_options',
                'tx_mpcvidply_show_track_info',
                'tx_mpcvidply_resume_playback'
            )
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in(
                    'CType',
                    $queryBuilder->createNamedParameter(
                        ['mpc_vidply', 'mpc_vidply_detail'],
                        Connection::PARAM_STR_ARRAY
                    )
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $pending = [];
        foreach ($rows as $row) {
            $options = (int)($row['tx_mpcvidply_options'] ?? 0);
            $showTrackInfo = (int)($row['tx_mpcvidply_show_track_info'] ?? 0);
            $resumePlayback = (int)($row['tx_mpcvidply_resume_playback'] ?? 0);
            $migrated = $this->migrateRecord($options, $showTrackInfo, $resumePlayback);

            if (
                $migrated['options'] !== $options
                || $migrated['showTrackInfo'] !== $showTrackInfo
                || $migrated['resumePlayback'] !== $resumePlayback
            ) {
                $pending[] = [
                    'uid' => (int)$row['uid'],
                    'options' => $options,
                    'showTrackInfo' => $showTrackInfo,
                    'resumePlayback' => $resumePlayback,
                    'newOptions' => $migrated['options'],
                    'newShowTrackInfo' => $migrated['showTrackInfo'],
                    'newResumePlayback' => $migrated['resumePlayback'],
                ];
            }
        }

        return $pending;
    }

    public function migrateAll(): int
    {
        $updated = 0;
        foreach ($this->findRecordsNeedingMigration() as $record) {
            GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('tt_content')
                ->update(
                    'tt_content',
                    [
                        'tx_mpcvidply_options' => $record['newOptions'],
                        'tx_mpcvidply_show_track_info' => $record['newShowTrackInfo'],
                        'tx_mpcvidply_resume_playback' => $record['newResumePlayback'],
                    ],
                    ['uid' => $record['uid']]
                );
            ++$updated;
        }

        return $updated;
    }
}
