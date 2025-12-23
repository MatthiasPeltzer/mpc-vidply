<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tca;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * TCA Label User Function for Privacy Settings
 * 
 * Generates a descriptive label for privacy settings records in the list view
 */
class PrivacySettingsLabel
{
    /**
     * Generate label for privacy settings record
     * 
     * @param array $parameters Parameters array with 'row' key containing record data
     * @return void
     */
    public function getLabel(array &$parameters): void
    {
        $row = $parameters['row'];
        
        // Get language service
        $languageService = $GLOBALS['LANG'] ?? GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
        
        // Translate the base label from backend language file
        $label = $languageService->sL('LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tx_mpcvidply_privacy_settings') ?: 'Privacy Layer Settings';
        
        // Get language name if translated record (TYPO3 13/14: use SiteLanguage instead of sys_language table)
        $languageId = (int)($row['sys_language_uid'] ?? 0);
        if ($languageId > 0) {
            $languageTitle = $this->getLanguageTitle($languageId);
            if ($languageTitle !== null) {
                $label .= ' (' . $languageTitle . ')';
            } else {
                $label .= ' (Language ' . $languageId . ')';
            }
        }
        
        $parameters['title'] = $label;
    }
    
    /**
     * Get language title from site configuration (TYPO3 13/14 compatible)
     * 
     * @param int $languageId Language ID
     * @return string|null Language title or null if not found
     */
    private function getLanguageTitle(int $languageId): ?string
    {
        try {
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            $sites = $siteFinder->getAllSites();
            
            // Try to find language in any site configuration
            foreach ($sites as $site) {
                try {
                    $siteLanguage = $site->getLanguageById($languageId);
                    return $siteLanguage->getTitle();
                } catch (\InvalidArgumentException $e) {
                    // Language not found in this site, continue
                    continue;
                }
            }
        } catch (\Exception $e) {
            // Silently fail
        }
        
        return null;
    }
}

