<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Upgrades;

use Mpc\MpcVidply\Service\Player\PlayerOptionsFieldMigrationService;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

#[UpgradeWizard('mpcVidplyMigratePlayerOptionFields')]
final readonly class MigratePlayerOptionsFieldUpgradeWizard implements UpgradeWizardInterface
{
    public function __construct(
        private PlayerOptionsFieldMigrationService $migrationService,
    ) {}

    public function getTitle(): string
    {
        return 'VidPly: Move resume playback and show track info to dedicated fields';
    }

    public function getDescription(): string
    {
        $pending = $this->migrationService->findRecordsNeedingMigration();
        if ($pending === []) {
            return 'Resume playback and show track info already use dedicated content element fields.';
        }

        return sprintf(
            'Updates %d VidPly content element(s): copies resume playback and show track info out of '
            . 'the player options bitmask into `tx_mpcvidply_resume_playback` and '
            . '`tx_mpcvidply_show_track_info`, then clears the legacy bitmask bits.',
            count($pending)
        );
    }

    public function updateNecessary(): bool
    {
        return $this->migrationService->findRecordsNeedingMigration() !== [];
    }

    public function executeUpdate(): bool
    {
        $this->migrationService->migrateAll();

        return true;
    }

    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }
}
