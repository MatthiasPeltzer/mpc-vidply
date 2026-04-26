<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\DataProcessing;

use Mpc\MpcVidply\Service\DetailRequestResolver;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

/**
 * Page-level DataProcessor that rewrites the breadcrumb "current" item so it
 * reflects the resolved media title on a VidPly detail page.
 *
 * Must run AFTER the breadcrumb MenuProcessor (mp-core uses priority 70, so
 * wire this processor at priority 71 or later).
 *
 * Configuration:
 *   - `as` (string, default: "breadcrumb"): key under which MenuProcessor
 *     stored its items. Must match the upstream MenuProcessor's `as` value.
 *   - `titleAs` (string, default: "vidplyDetailTitle"): additional Fluid
 *     variable that receives the resolved media title (useful for headers).
 */
final class DetailBreadcrumbProcessor implements DataProcessorInterface
{
    private readonly DetailRequestResolver $resolver;

    public function __construct(?DetailRequestResolver $resolver = null)
    {
        $this->resolver = $resolver ?? GeneralUtility::makeInstance(DetailRequestResolver::class);
    }

    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ): array {
        $request = $cObj->getRequest();
        $media = $this->resolver->resolveFromRequest($request);
        if ($media === null) {
            return $processedData;
        }

        $title = trim((string)($media['title'] ?? ''));
        if ($title === '') {
            return $processedData;
        }

        $breadcrumbKey = (string)($processorConfiguration['as'] ?? 'breadcrumb');
        $titleKey = (string)($processorConfiguration['titleAs'] ?? 'vidplyDetailTitle');

        if (isset($processedData[$breadcrumbKey]) && is_array($processedData[$breadcrumbKey])) {
            $processedData[$breadcrumbKey] = $this->overrideCurrentItem(
                $processedData[$breadcrumbKey],
                $title
            );
        }

        $processedData[$titleKey] = $title;

        return $processedData;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function overrideCurrentItem(array $items, string $title): array
    {
        if ($items === []) {
            return $items;
        }

        foreach ($items as $idx => $item) {
            if ((int)($item['current'] ?? 0) === 1) {
                $items[$idx]['title'] = $title;
                return $items;
            }
        }

        // Fallback: MenuProcessor normally flags the last rootline item as current,
        // but when that signal is missing (e.g. custom menu post-processing) we
        // override the final item so the breadcrumb still reflects the media.
        $lastIdx = array_key_last($items);
        if ($lastIdx !== null) {
            $items[$lastIdx]['title'] = $title;
        }
        return $items;
    }
}
