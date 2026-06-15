<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Upgrades;

use Mpc\MpcVidply\Service\SrtCaptionMigrationService;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\ConfirmableInterface;
use TYPO3\CMS\Install\Updates\Confirmation;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

#[UpgradeWizard('mpcVidplyConvertSrtCaptions')]
final readonly class ConvertSrtCaptionsUpgradeWizard implements UpgradeWizardInterface, ConfirmableInterface
{
    public function __construct(
        private SrtCaptionMigrationService $migrationService,
    ) {}

    public function getTitle(): string
    {
        return 'VidPly: Convert SRT caption files to WebVTT';
    }

    public function getDescription(): string
    {
        $references = $this->migrationService->findAllSrtReferences();
        if ($references === []) {
            return 'No VidPly-linked SRT subtitle files were found.';
        }

        $description = sprintf(
            'Found %d VidPly-linked SRT subtitle file(s) that will be converted to WebVTT:',
            count($references)
        );

        foreach ($references as $reference) {
            $mediaLabel = $reference['media_title'] !== ''
                ? $reference['media_title']
                : ('Media UID ' . $reference['media_uid']);
            $description .= LF . sprintf(
                '  • %s (%s / %s — %s)',
                $reference['file_name'],
                $reference['tablenames'],
                $reference['fieldname'],
                $mediaLabel
            );
        }

        $description .= LF . LF . 'Original SRT files will be replaced by WebVTT files with the same base name.';

        return $description;
    }

    public function getConfirmation(): Confirmation
    {
        return new Confirmation(
            'Convert SRT caption files to WebVTT?',
            'This replaces VidPly-linked SRT subtitle files with WebVTT equivalents. '
            . 'Original SRT files will not be kept. Please ensure you have a backup before proceeding.',
            false,
            'Yes, convert to WebVTT',
            'No, keep SRT files'
        );
    }

    public function updateNecessary(): bool
    {
        return $this->migrationService->findAllSrtReferences() !== [];
    }

    public function executeUpdate(): bool
    {
        $batchResult = $this->migrationService->migrateAll();

        return $batchResult->failedCount() === 0;
    }

    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }
}
