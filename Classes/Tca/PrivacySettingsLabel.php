<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tca;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageService;

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
        $languageService = $GLOBALS['LANG'] ?? \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(LanguageService::class);
        
        // Translate the base label from backend language file
        $label = $languageService->sL('LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tx_mpcvidply_privacy_settings') ?: 'Privacy Layer Settings';
        
        // Get language name if translated record
        $languageId = (int)($row['sys_language_uid'] ?? 0);
        if ($languageId > 0) {
            $language = BackendUtility::getRecord('sys_language', $languageId);
            if ($language) {
                $label .= ' (' . ($language['title'] ?? 'Language ' . $languageId) . ')';
            }
        }
        
        $parameters['title'] = $label;
    }
}

