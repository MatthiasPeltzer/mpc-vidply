<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service to fetch privacy layer settings from database.
 *
 * Provides site-wide configuration for privacy layer texts, links, and button labels
 * for YouTube, Vimeo, and SoundCloud.
 * Supports multilingual content via sys_language_uid.
 *
 * Uses constructor injection with fallback for TYPO3 13/14 compatibility.
 */
final class PrivacySettingsService
{
    private readonly ConnectionPool $connectionPool;
    private readonly LanguageServiceFactory $languageServiceFactory;
    private readonly SiteRecordScope $siteRecordScope;

    /**
     * @var array<string, array<string, mixed>|null>
     */
    private array $settingsCache = [];

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?LanguageServiceFactory $languageServiceFactory = null,
        ?SiteRecordScope $siteRecordScope = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->languageServiceFactory = $languageServiceFactory ?? GeneralUtility::makeInstance(LanguageServiceFactory::class);
        $this->siteRecordScope = $siteRecordScope ?? GeneralUtility::makeInstance(SiteRecordScope::class);
    }

    /**
     * Get privacy settings for a specific service.
     *
     * @param string $service Service name: 'youtube', 'vimeo', or 'soundcloud'
     * @param int $languageId Language ID (0 for default language)
     * @param ServerRequestInterface|null $request Current request for language resolution (avoids $GLOBALS access)
     * @return array<string, string>
     */
    public function getSettingsForService(string $service, int $languageId = 0, ?ServerRequestInterface $request = null): array
    {
        $settings = $this->getAllSettings($languageId, $request);

        if ($settings === null) {
            return $this->getFallbackSettings($service, $languageId, $request);
        }

        $serviceKey = $service . '_';
        $recordLanguageId = (int)($settings['sys_language_uid'] ?? 0);

        $result = [
            'headline' => $this->sanitizePrivacyText((string)($settings[$serviceKey . 'headline'] ?? ''), 255),
            'intro_text' => $this->sanitizePrivacyText((string)($settings[$serviceKey . 'intro_text'] ?? ''), 2000),
            'outro_text' => $this->sanitizePrivacyText((string)($settings[$serviceKey . 'outro_text'] ?? ''), 2000),
            'policy_link' => trim((string)($settings[$serviceKey . 'policy_link'] ?? '')),
            'link_text' => $this->sanitizePrivacyText((string)($settings[$serviceKey . 'link_text'] ?? ''), 255),
            'button_label' => $this->sanitizePrivacyText((string)($settings[$serviceKey . 'button_label'] ?? ''), 255),
        ];

        $useDbValues = $languageId === 0 || $recordLanguageId === $languageId;
        if (!$useDbValues) {
            return $this->getFallbackSettings($service, $languageId, $request);
        }

        if ($result['intro_text'] === '') {
            $result['intro_text'] = $this->getDefaultIntroText($service, $languageId, $request);
        }
        if ($result['outro_text'] === '') {
            $result['outro_text'] = $this->getDefaultOutroText($languageId, $request);
        }
        if ($result['policy_link'] === '' || !$this->isHttpUrl($result['policy_link'])) {
            $result['policy_link'] = $this->getDefaultPolicyLink($service);
        }
        if ($result['link_text'] === '') {
            $result['link_text'] = $this->getDefaultLinkText($service, $languageId, $request);
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
     * Settings record of the site the request belongs to. Records stored
     * outside of every site tree are shared by all sites; a record of the
     * request's own site wins over a shared one. The result is cached per
     * site and language, as it is read once per service and player.
     *
     * @param int $languageId Language ID (0 for default language)
     * @return array<string, mixed>|null Settings array or null if not found
     */
    public function getAllSettings(int $languageId = 0, ?ServerRequestInterface $request = null): ?array
    {
        $request ??= $GLOBALS['TYPO3_REQUEST'] ?? null;
        $site = $request instanceof ServerRequestInterface ? $request->getAttribute('site') : null;
        $site = $site instanceof SiteInterface ? $site : null;

        $cacheKey = ($site instanceof Site ? $site->getIdentifier() : '') . '|' . $languageId;
        if (array_key_exists($cacheKey, $this->settingsCache)) {
            return $this->settingsCache[$cacheKey];
        }

        $settings = null;
        if ($languageId > 0) {
            $settings = $this->findSettingsForSite($languageId, $site);
        }
        $settings ??= $this->findSettingsForSite(0, $site);

        return $this->settingsCache[$cacheKey] = $settings;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSettingsForSite(int $languageId, ?SiteInterface $site): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_mpcvidply_privacy_settings');
        $rows = $qb
            ->select('pid', ...self::SETTINGS_COLUMNS)
            ->from('tx_mpcvidply_privacy_settings')
            ->where(
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $shared = null;
        foreach ($rows as $row) {
            $pid = (int)($row['pid'] ?? 0);
            if (!$this->siteRecordScope->isPageInSite($pid, $site)) {
                continue;
            }
            if (!$site instanceof Site || $this->siteRecordScope->getSiteIdentifier($pid) !== null) {
                return $row;
            }
            $shared ??= $row;
        }

        return $shared;
    }

    /**
     * @return array<string, string>
     */
    private function getFallbackSettings(string $service, int $languageId = 0, ?ServerRequestInterface $request = null): array
    {
        return [
            'headline' => '',
            'intro_text' => $this->getDefaultIntroText($service, $languageId, $request),
            'outro_text' => $this->getDefaultOutroText($languageId, $request),
            'policy_link' => $this->getDefaultPolicyLink($service),
            'link_text' => $this->getDefaultLinkText($service, $languageId, $request),
            'button_label' => '',
        ];
    }

    private function getDefaultIntroText(string $service, int $languageId = 0, ?ServerRequestInterface $request = null): string
    {
        $languageService = $this->getLanguageService($languageId, $request);
        $key = $service === 'soundcloud' ? 'privacy.activate_intro_widget' : 'privacy.activate_intro';
        return $languageService->sL('LLL:EXT:mpc_vidply/Resources/Private/Language/locallang.xlf:' . $key) ?: '';
    }

    private function getDefaultOutroText(int $languageId = 0, ?ServerRequestInterface $request = null): string
    {
        $languageService = $this->getLanguageService($languageId, $request);
        return $languageService->sL('LLL:EXT:mpc_vidply/Resources/Private/Language/locallang.xlf:privacy.activate_outro') ?: '';
    }

    private function getDefaultLinkText(string $service, int $languageId = 0, ?ServerRequestInterface $request = null): string
    {
        $languageService = $this->getLanguageService($languageId, $request);
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

    /**
     * Strip control characters (except TAB, LF, CR) and clamp the string to $maxLength
     * characters. The output is still HTML-escaped by Fluid when emitted; this only
     * protects against DB-sourced control characters and unbounded length.
     */
    private function sanitizePrivacyText(string $value, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = (string)preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $value);
        if ($maxLength > 0 && mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }
        return $value;
    }

    private function getLanguageService(int $languageId = 0, ?ServerRequestInterface $request = null): \TYPO3\CMS\Core\Localization\LanguageService
    {
        $request ??= $GLOBALS['TYPO3_REQUEST'] ?? null;
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
