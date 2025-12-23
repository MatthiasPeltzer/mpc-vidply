<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service to fetch privacy layer settings from database
 * 
 * Provides site-wide configuration for privacy layer texts, links, and button labels
 * for YouTube, Vimeo, and SoundCloud.
 * Supports multilingual content via sys_language_uid.
 * 
 * @package Mpc\MpcVidply\Service
 */
class PrivacySettingsService
{
    private readonly ConnectionPool $connectionPool;
    private readonly LanguageServiceFactory $languageServiceFactory;
    private readonly SiteFinder $siteFinder;

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?LanguageServiceFactory $languageServiceFactory = null,
        ?SiteFinder $siteFinder = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->languageServiceFactory = $languageServiceFactory ?? GeneralUtility::makeInstance(LanguageServiceFactory::class);
        $this->siteFinder = $siteFinder ?? GeneralUtility::makeInstance(SiteFinder::class);
    }

    /**
     * Get privacy settings for a specific service
     * 
     * @param string $service Service name: 'youtube', 'vimeo', or 'soundcloud'
     * @param int $languageId Language ID (0 for default language)
     * @return array Array with keys: headline, intro_text, outro_text, policy_link, link_text, button_label
     */
    public function getSettingsForService(string $service, int $languageId = 0): array
    {
        $settings = $this->getAllSettings($languageId);
        
        if ($settings === null) {
            // No record exists at all - use language file fallbacks
            return $this->getFallbackSettings($service, $languageId);
        }
        
        $serviceKey = $service . '_';
        $recordLanguageId = (int)($settings['sys_language_uid'] ?? 0);
        
        // Extract values from database
        $result = [
            'headline' => $settings[$serviceKey . 'headline'] ?? '',
            'intro_text' => $settings[$serviceKey . 'intro_text'] ?? '',
            'outro_text' => $settings[$serviceKey . 'outro_text'] ?? '',
            'policy_link' => $settings[$serviceKey . 'policy_link'] ?? '',
            'link_text' => $settings[$serviceKey . 'link_text'] ?? '',
            'button_label' => $settings[$serviceKey . 'button_label'] ?? '',
        ];
        
        // For translated languages (languageId > 0):
        // - If translated record exists but is empty, use language file fallbacks (not default language DB values)
        // - If no translated record exists (we got default language record), use language file fallbacks
        // For default language (languageId === 0):
        // - Use default language DB values if set, otherwise language file fallbacks
        if ($languageId > 0) {
            // Check if we actually got a translated record
            if ($recordLanguageId === $languageId) {
                // Translated record exists - if empty, use language file fallbacks for this language
                if (empty($result['intro_text'])) {
                    $result['intro_text'] = $this->getDefaultIntroText($service, $languageId);
                }
                if (empty($result['outro_text'])) {
                    $result['outro_text'] = $this->getDefaultOutroText($languageId);
                }
                if (empty($result['policy_link'])) {
                    $result['policy_link'] = $this->getDefaultPolicyLink($service);
                }
                if (empty($result['link_text'])) {
                    $result['link_text'] = $this->getDefaultLinkText($service, $languageId);
                }
            } else {
                // No translated record exists (we got default language record) - use language file fallbacks for requested language
                $result = $this->getFallbackSettings($service, $languageId);
            }
        } else {
            // Default language (languageId === 0) - use language file fallbacks if DB values are empty
            if (empty($result['intro_text'])) {
                $result['intro_text'] = $this->getDefaultIntroText($service, $languageId);
            }
            if (empty($result['outro_text'])) {
                $result['outro_text'] = $this->getDefaultOutroText($languageId);
            }
            if (empty($result['policy_link'])) {
                $result['policy_link'] = $this->getDefaultPolicyLink($service);
            }
            if (empty($result['link_text'])) {
                $result['link_text'] = $this->getDefaultLinkText($service, $languageId);
            }
        }
        
        return $result;
    }

    /**
     * Get all privacy settings
     * 
     * @param int $languageId Language ID (0 for default language)
     * @return array|null Settings array or null if not found
     */
    public function getAllSettings(int $languageId = 0): ?array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('tx_mpcvidply_privacy_settings');
        
        // Try to get translated version first if languageId > 0
        if ($languageId > 0) {
            $translatedSettings = $queryBuilder
                ->select('*')
                ->from('tx_mpcvidply_privacy_settings')
                ->where(
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
            
            if ($translatedSettings !== false) {
                return $translatedSettings;
            }
        }
        
        // Get default language settings
        $settings = $queryBuilder
            ->select('*')
            ->from('tx_mpcvidply_privacy_settings')
            ->where(
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        
        return $settings !== false ? $settings : null;
    }

    /**
     * Get fallback settings when no database record exists
     */
    private function getFallbackSettings(string $service, int $languageId = 0): array
    {
        return [
            'headline' => '',
            'intro_text' => $this->getDefaultIntroText($service, $languageId),
            'outro_text' => $this->getDefaultOutroText($languageId),
            'policy_link' => $this->getDefaultPolicyLink($service),
            'link_text' => $this->getDefaultLinkText($service, $languageId),
            'button_label' => '',
        ];
    }

    /**
     * Get default intro text from language file
     */
    private function getDefaultIntroText(string $service, int $languageId = 0): string
    {
        $languageService = $this->getLanguageService($languageId);
        $key = $service === 'soundcloud' ? 'privacy.activate_intro_widget' : 'privacy.activate_intro';
        return $languageService->sL('LLL:EXT:mpc_vidply/Resources/Private/Language/locallang.xlf:' . $key) ?: '';
    }

    /**
     * Get default outro text from language file
     */
    private function getDefaultOutroText(int $languageId = 0): string
    {
        $languageService = $this->getLanguageService($languageId);
        return $languageService->sL('LLL:EXT:mpc_vidply/Resources/Private/Language/locallang.xlf:privacy.activate_outro') ?: '';
    }

    /**
     * Get default link text from language file
     */
    private function getDefaultLinkText(string $service, int $languageId = 0): string
    {
        $languageService = $this->getLanguageService($languageId);
        $key = match ($service) {
            'youtube' => 'privacy.youtube.policy_link',
            'vimeo' => 'privacy.vimeo.policy_link',
            'soundcloud' => 'privacy.soundcloud.policy_link',
            default => '',
        };
        if (empty($key)) {
            return '';
        }
        return $languageService->sL('LLL:EXT:mpc_vidply/Resources/Private/Language/locallang.xlf:' . $key) ?: '';
    }

    /**
     * Get LanguageService for the given language ID
     */
    private function getLanguageService(int $languageId = 0): \TYPO3\CMS\Core\Localization\LanguageService
    {
        // Try to get SiteLanguage from current request
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request !== null) {
            $siteLanguage = $request->getAttribute('language');
            if ($siteLanguage !== null) {
                // If languageId is 0 (default), use current site language directly
                if ($languageId === 0) {
                    return $this->languageServiceFactory->createFromSiteLanguage($siteLanguage);
                }
                // Otherwise, check if current language matches requested language
                // @extensionScannerIgnoreLine
                // This is SiteLanguage->getLanguageId(), not AbstractSectionMarkupGeneratedEvent->getLanguageId()
                if ($siteLanguage->getLanguageId() === $languageId) {
                    return $this->languageServiceFactory->createFromSiteLanguage($siteLanguage);
                }
            }
        }

        // Fallback: try to get language from site finder
        try {
            $pageId = 0;
            if ($request !== null) {
                // Try to get page ID from frontend page information (modern way)
                $pageInformation = $request->getAttribute('frontend.page.information');
                if ($pageInformation !== null && method_exists($pageInformation, 'getId')) {
                    $pageId = $pageInformation->getId() ?? 0;
                }
                
                // Fallback: try routing attribute
                if ($pageId === 0) {
                    $routing = $request->getAttribute('routing');
                    if ($routing !== null && method_exists($routing, 'getPageId')) {
                        $pageId = $routing->getPageId() ?? 0;
                    }
                }
                
                // Fallback: try site root page ID
                if ($pageId === 0) {
                    $site = $request->getAttribute('site');
                    if ($site !== null) {
                        $pageId = $site->getRootPageId();
                    }
                }
            }
            
            if ($pageId > 0) {
                $site = $this->siteFinder->getSiteByPageId($pageId);
                if ($languageId > 0) {
                    $siteLanguage = $site->getLanguageById($languageId);
                    if ($siteLanguage !== null) {
                        return $this->languageServiceFactory->createFromSiteLanguage($siteLanguage);
                    }
                }
                // Use default language
                $siteLanguage = $site->getDefaultLanguage();
                return $this->languageServiceFactory->createFromSiteLanguage($siteLanguage);
            }
        } catch (\Exception $e) {
            // Ignore and fall through to final fallback
        }
        
        // Final fallback: use default language
        return $this->languageServiceFactory->create('default');
    }

    /**
     * Get default policy link for service
     */
    private function getDefaultPolicyLink(string $service): string
    {
        return match ($service) {
            'youtube' => 'https://policies.google.com/privacy',
            'vimeo' => 'https://vimeo.com/privacy',
            'soundcloud' => 'https://soundcloud.com/pages/privacy',
            default => '',
        };
    }
}
