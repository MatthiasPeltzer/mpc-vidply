<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * Builds the detail-page URL for a media record.
 *
 * The generated URL always carries `media=<uid>`. TYPO3's VidPlyDetail route
 * enhancer ({@see Configuration/Sets/mpc-vidply/route-enhancers.yaml}) then
 * rewrites the parameter to the speaking slug segment. If the record has no
 * slug, {@see \Mpc\MpcVidply\Routing\Aspect\VidPlyMediaRouteAspect} makes the
 * enhancer step down so the PageRouter returns `?media=<uid>&cHash=…` (no
 * unreliable `&id=<pageId>`). The query string is never assembled by hand.
 */
final class DetailUrlBuilder
{
    private readonly MediaUrlNormalizer $urlNormalizer;

    public function __construct(?MediaUrlNormalizer $urlNormalizer = null)
    {
        $this->urlNormalizer = $urlNormalizer ?? GeneralUtility::makeInstance(MediaUrlNormalizer::class);
    }

    /**
     * @param bool $absolute Structured data needs fully qualified URLs; in-page
     *                       links stay relative.
     */
    public function build(
        ContentObjectRenderer $cObj,
        int $pageUid,
        int $mediaUid,
        string $slug,
        int $languageId,
        bool $absolute = false
    ): string {
        if ($pageUid <= 0 || $mediaUid <= 0) {
            return '';
        }

        $config = [
            'parameter' => $pageUid,
            'additionalParams' => '&media=' . rawurlencode((string)$mediaUid),
            'forceAbsoluteUrl' => $absolute ? 1 : 0,
            'returnLast' => 'url',
        ];
        if ($languageId > 0) {
            $config['language'] = $languageId;
        }

        try {
            $url = (string)$cObj->typoLink_URL($config);
        } catch (\Throwable) {
            $url = '';
        }

        if ($url !== '') {
            return $url;
        }

        // Last-resort: no `id`, site routing removes it; a relative query is
        // enough on the same host.
        $fallback = $slug !== '' ? '/' . ltrim($slug, '/') : '?media=' . $mediaUid;

        if (!$absolute) {
            return $fallback;
        }

        return $this->urlNormalizer->makeAbsolute($fallback, $cObj->getRequest());
    }
}
