<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tca;

use Mpc\MpcVidply\Utility\RecordAwareValueResolver;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class PrivacySettingsLabel
{
    private LanguageServiceFactory $languageServiceFactory;
    private SiteFinder $siteFinder;

    public function __construct(
        ?LanguageServiceFactory $languageServiceFactory = null,
        ?SiteFinder $siteFinder = null
    ) {
        $this->languageServiceFactory = $languageServiceFactory ?? GeneralUtility::makeInstance(LanguageServiceFactory::class);
        $this->siteFinder = $siteFinder ?? GeneralUtility::makeInstance(SiteFinder::class);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function getLabel(array &$parameters): void
    {
        $row = RecordAwareValueResolver::normalizeToArray($parameters['row']);

        $languageService = $GLOBALS['LANG'] ?? $this->languageServiceFactory->create('default');
        $label = $languageService->sL('LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tx_mpcvidply_privacy_settings') ?: 'Privacy Layer Settings';

        $languageId = RecordAwareValueResolver::resolveInt($row['sys_language_uid'] ?? null);
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
