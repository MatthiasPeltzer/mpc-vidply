<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tca;

use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class PrivacySettingsLabel
{
    private readonly LanguageServiceFactory $languageServiceFactory;
    private readonly SiteFinder $siteFinder;

    public function __construct(
        ?LanguageServiceFactory $languageServiceFactory = null,
        ?SiteFinder $siteFinder = null
    ) {
        $this->languageServiceFactory = $languageServiceFactory ?? GeneralUtility::makeInstance(LanguageServiceFactory::class);
        $this->siteFinder = $siteFinder ?? GeneralUtility::makeInstance(SiteFinder::class);
    }

    public function getLabel(array &$parameters): void
    {
        $row = $parameters['row'];

        $languageService = $GLOBALS['LANG'] ?? $this->languageServiceFactory->create('default');
        $label = $languageService->sL('LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tx_mpcvidply_privacy_settings') ?: 'Privacy Layer Settings';

        $languageId = (int)($row['sys_language_uid'] ?? 0);
        if ($languageId > 0) {
            $languageTitle = $this->getLanguageTitle($languageId);
            if ($languageTitle !== null) {
                $label .= ' (' . $languageTitle . ')';
            } else {
                $fallback = $languageService->sL('LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:preview.language_fallback') ?: 'Language %d';
                $label .= ' (' . sprintf($fallback, $languageId) . ')';
            }
        }

        $parameters['title'] = $label;
    }

    private function getLanguageTitle(int $languageId): ?string
    {
        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                try {
                    return $site->getLanguageById($languageId)->getTitle();
                } catch (\InvalidArgumentException) {
                    continue;
                }
            }
        } catch (\Exception) {
        }

        return null;
    }
}
