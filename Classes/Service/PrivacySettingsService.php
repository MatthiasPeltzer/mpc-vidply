<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
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

    public function __construct(?ConnectionPool $connectionPool = null)
    {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
    }

    /**
     * Get privacy settings for a specific service
     * 
     * @param string $service Service name: 'youtube', 'vimeo', or 'soundcloud'
     * @param int $languageId Language ID (0 for default language)
     * @return array Array with keys: intro_text, outro_text, policy_link, button_label
     */
    public function getSettingsForService(string $service, int $languageId = 0): array
    {
        $settings = $this->getAllSettings($languageId);
        
        if ($settings === null) {
            // Return empty array with fallbacks
            return $this->getFallbackSettings($service);
        }
        
        $serviceKey = $service . '_';
        $result = [
            'headline' => $settings[$serviceKey . 'headline'] ?? '',
            'intro_text' => $settings[$serviceKey . 'intro_text'] ?? '',
            'outro_text' => $settings[$serviceKey . 'outro_text'] ?? '',
            'policy_link' => $settings[$serviceKey . 'policy_link'] ?? '',
            'link_text' => $settings[$serviceKey . 'link_text'] ?? '',
            'button_label' => $settings[$serviceKey . 'button_label'] ?? '',
        ];
        
        // Fallback to default language if current language has empty values
        if (empty($result['intro_text']) && $languageId > 0) {
            $defaultSettings = $this->getAllSettings(0);
            if ($defaultSettings !== null) {
                $result['headline'] = $defaultSettings[$serviceKey . 'headline'] ?? '';
                $result['intro_text'] = $defaultSettings[$serviceKey . 'intro_text'] ?? '';
                $result['outro_text'] = $defaultSettings[$serviceKey . 'outro_text'] ?? '';
                $result['policy_link'] = $defaultSettings[$serviceKey . 'policy_link'] ?? '';
                $result['link_text'] = $defaultSettings[$serviceKey . 'link_text'] ?? '';
                $result['button_label'] = $defaultSettings[$serviceKey . 'button_label'] ?? '';
            }
        }
        
        // Fallback to language file translations if still empty
        if (empty($result['intro_text'])) {
            $result['intro_text'] = $this->getDefaultIntroText($service);
        }
        if (empty($result['outro_text'])) {
            $result['outro_text'] = $this->getDefaultOutroText();
        }
        if (empty($result['policy_link'])) {
            $result['policy_link'] = $this->getDefaultPolicyLink($service);
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
    private function getFallbackSettings(string $service): array
    {
        return [
            'intro_text' => $this->getDefaultIntroText($service),
            'outro_text' => $this->getDefaultOutroText(),
            'policy_link' => $this->getDefaultPolicyLink($service),
            'button_label' => '',
        ];
    }

    /**
     * Get default intro text from language file
     */
    private function getDefaultIntroText(string $service): string
    {
        if ($service === 'soundcloud') {
            return 'To activate the widget, you must click on the button. After activating the button,';
        }
        return 'To activate the video, you must click on the button. After activating the button,';
    }

    /**
     * Get default outro text from language file
     */
    private function getDefaultOutroText(): string
    {
        return 'applies.';
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
