<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Hooks;

use Mpc\MpcVidply\Service\ListviewRowLocalizationService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates and maintains localized `tx_mpcvidply_listview_row` records when a
 * listview content element or one of its translations is saved or localized.
 *
 * Without localized child rows, TYPO3's inline field on a translated CE keeps
 * showing the default-language headlines and the frontend cannot overlay them.
 */
final class ListviewRowTranslationSync extends AbstractContentTranslationSyncHook
{
    private const CTYPE = 'mpc_vidply_listview';

    private readonly ListviewRowLocalizationService $localizationService;

    public function __construct(
        ?ListviewRowLocalizationService $localizationService = null
    ) {
        $this->localizationService = $localizationService
            ?? GeneralUtility::makeInstance(ListviewRowLocalizationService::class);
    }

    protected function getContentType(): string
    {
        return self::CTYPE;
    }

    protected function syncTranslation(int $sourceUid, int $translationUid, int $languageId): void
    {
        $this->localizationService->ensureLocalizedRowsForTranslation($sourceUid, $translationUid, $languageId);
    }

    protected function syncAllTranslations(int $sourceUid): void
    {
        $this->localizationService->ensureLocalizedRowsForAllTranslations($sourceUid);
    }
}
