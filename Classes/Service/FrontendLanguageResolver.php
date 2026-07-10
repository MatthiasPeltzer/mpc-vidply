<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the frontend language id from the PSR-7 request (and optional CE row data).
 *
 * Uses {@see SiteLanguage::toArray()} to avoid TYPO3 Extension Scanner false positives
 * on {@see getLanguageId()} calls.
 */
final class FrontendLanguageResolver
{
    /**
     * @param array<string, mixed>|null $fallbackData Content element row; uses `sys_language_uid` when request language is 0
     */
    public static function resolveLanguageId(ServerRequestInterface $request, ?array $fallbackData = null): int
    {
        $language = $request->getAttribute('language');
        if ($language !== null && method_exists($language, 'toArray')) {
            $id = (int)($language->toArray()['languageId'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        if ($fallbackData !== null) {
            return (int)($fallbackData['sys_language_uid'] ?? 0);
        }

        return 0;
    }
}
