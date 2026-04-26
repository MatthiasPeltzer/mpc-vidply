<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Routing\Aspect;

use TYPO3\CMS\Core\Routing\Aspect\PersistedAliasMapper;

/**
 * {@see PersistedAliasMapper} for `tx_mpcvidply_media.slug`, with one fix:
 * if the record has an empty slug, {@see ::generate} returns `null` so
 * {@see \TYPO3\CMS\Core\Routing\PageRouter} skips the `/{media}` enhancer
 * and builds the default page URL with `?media=<uid>&cHash=…` instead.
 *
 * The core mapper returns an empty string for a missing/empty DB value; that
 * fails the enhancer’s route `requirements` and can make typolink return no
 * URL, so templates fell back to `?id=<pageId>&media=<uid>`, which is a poor
 * fit for site-based routing. Returning `null` is the correct signal: see
 * {@see \TYPO3\CMS\Core\Routing\Aspect\MappableProcessor::generate()}.
 */
final class VidPlyMediaRouteAspect extends PersistedAliasMapper
{
    public function generate(string $value): ?string
    {
        $out = parent::generate($value);
        if ($out === null) {
            return null;
        }
        if (trim($out) === '') {
            return null;
        }
        return $out;
    }
}
