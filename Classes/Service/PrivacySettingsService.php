<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service to fetch privacy layer settings from database
 *
 * Provides site-wide configuration for privacy layer texts, links, and button labels
 * for YouTube, Vimeo, and SoundCloud.
 * Supports multilingual content via sys_language_uid.
 */
class PrivacySettingsService
{
    private readonly ConnectionPool $connectionPool;
    private readonly LanguageServiceFactory $languageServiceFactory;

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?LanguageServiceFactory $languageServiceFactory = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->languageServiceFactory = $languageServiceFactory ?? GeneralUtility::makeInstance(LanguageServiceFactory::class);
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
        
        $result = [
            'headline' => (string)($settings[$serviceKey . 'headline'] ?? ''),
            'intro_text' => (string)($settings[$serviceKey . 'intro_text'] ?? ''),
            'outro_text' => (string)($settings[$serviceKey . 'outro_text'] ?? ''),
            'policy_link' => (string)($settings[$serviceKey . 'policy_link'] ?? ''),
            'link_text' => (string)($settings[$serviceKey . 'link_text'] ?? ''),
            'button_label' => (string)($settings[$serviceKey . 'button_label'] ?? ''),
        ];

        $useDbValues = $languageId === 0 || $recordLanguageId === $languageId;
        if (!$useDbValues) {
            return $this->getFallbackSettings($service, $languageId);
        }

        if ($result['intro_text'] === '') {
            $result['intro_text'] = $this->getDefaultIntroText($service, $languageId);
        }
        if ($result['outro_text'] === '') {
            $result['outro_text'] = $this->getDefaultOutroText($languageId);
        }
        if ($result['policy_link'] === '' || !$this->isHttpUrl($result['policy_link'])) {
            $result['policy_link'] = $this->getDefaultPolicyLink($service);
        }
        if ($result['link_text'] === '') {
            $result['link_text'] = $this->getDefaultLinkText($service, $languageId);
        }

        return $result;
    }

    private const SETTINGS_COLUMNS = [
        'sys_language_uid',
        'youtube_headline', 'youtube_intro_text', 'youtube_outro_text',
        'youtube_policy_link', 'youtube_link_text', 'youtube_button_label',
        'vimeo_headline', 'vimeo_intro_text', 'vimeo_outro_text',
        'vimeo_policy_link', 'vimeo_link_text', 'vimeo_button_label',
        'soundcloud_headline', 'soundcloud_intro_text', 'soundcloud_outro_text',
        'soundcloud_policy_link', 'soundcloud_link_text', 'soundcloud_button_label',
    ];

    /**
     * Get all privacy settings
     *
     * @param int $languageId Language ID (0 for default language)
     * @return array|null Settings array or null if not found
     */
    public function getAllSettings(int $languageId = 0): ?array
    {
        if ($languageId > 0) {
            $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_privacy_settings');
            $translatedSettings = $qb
                ->select(...self::SETTINGS_COLUMNS)
                ->from('tx_mpcvidply_privacy_settings')
                ->where(
                    $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, Connection::PARAM_INT)),
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                    $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT))
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if ($translatedSettings !== false) {
                return $translatedSettings;
            }
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_privacy_settings');
        $settings = $qb
            ->select(...self::SETTINGS_COLUMNS)
            ->from('tx_mpcvidply_privacy_settings')
            ->where(
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT))
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

    private function getDefaultLinkText(string $service, int $languageId = 0): string
    {
        $languageService = $this->getLanguageService($languageId);
        $key = match ($service) {
            'youtube' => 'privacy.youtube.policy_link',
            'vimeo' => 'privacy.vimeo.policy_link',
            'soundcloud' => 'privacy.soundcloud.policy_link',
            default => '',
        };
        if ($key === '') {
            return '';
        }
        return $languageService->sL('LLL:EXT:mpc_vidply/Resources/Private/Language/locallang.xlf:' . $key) ?: '';
    }

    private function isHttpUrl(string $url): bool
    {
        $scheme = parse_url(trim($url), PHP_URL_SCHEME);
        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    private function getLanguageService(int $languageId = 0): \TYPO3\CMS\Core\Localization\LanguageService
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $siteLanguage = $request?->getAttribute('language');

        if ($siteLanguage !== null) {
            // @extensionScannerIgnoreLine
            if ($languageId === 0 || $siteLanguage->getLanguageId() === $languageId) {
                return $this->languageServiceFactory->createFromSiteLanguage($siteLanguage);
            }
        }

        if ($languageId > 0 && $request !== null) {
            $site = $request->getAttribute('site');
            if ($site !== null) {
                try {
                    return $this->languageServiceFactory->createFromSiteLanguage(
                        $site->getLanguageById($languageId)
                    );
                } catch (\InvalidArgumentException) {
                }
            }
        }

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
