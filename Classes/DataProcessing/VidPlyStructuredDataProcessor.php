<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\DataProcessing;

use Mpc\MpcVidply\Service\MediaObjectJsonLdBuilder;
use Mpc\MpcVidply\Service\VidPlyPageMediaResolver;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

/**
 * Emits VidPly VideoObject / AudioObject JSON-LD in two modes:
 *
 * - **standalone** (default): writes encoded JSON-LD to {@see $processorConfiguration}
 *   `as` (default: `vidplyMediaJsonLd`) for mpc-vidply's own header partial. Works
 *   without mp-core.
 * - **merge**: appends the node to mp-core's `structuredDataJsonLd` @graph and links
 *   WebPage.mainEntity. Registered from ext_localconf.php only when mp-core is loaded.
 */
final class VidPlyStructuredDataProcessor implements DataProcessorInterface
{
    private readonly VidPlyPageMediaResolver $pageMediaResolver;
    private readonly MediaObjectJsonLdBuilder $jsonLdBuilder;

    public function __construct(
        ?VidPlyPageMediaResolver $pageMediaResolver = null,
        ?MediaObjectJsonLdBuilder $jsonLdBuilder = null
    ) {
        $this->pageMediaResolver = $pageMediaResolver ?? GeneralUtility::makeInstance(VidPlyPageMediaResolver::class);
        $this->jsonLdBuilder = $jsonLdBuilder ?? GeneralUtility::makeInstance(MediaObjectJsonLdBuilder::class);
    }

    /**
     * @param array<string, mixed> $contentObjectConfiguration
     * @param array<string, mixed> $processorConfiguration
     * @param array<string, mixed> $processedData
     * @return array<string, mixed>
     */
    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ): array {
        $mode = (string)($processorConfiguration['mode'] ?? 'standalone');
        $request = $cObj->getRequest();

        $structuredData = $this->pageMediaResolver->resolveStructuredData($request, $cObj);
        if ($structuredData === null) {
            return $processedData;
        }

        $schemaNode = $this->buildSchemaNode($structuredData, $request);
        if ($schemaNode === null) {
            return $processedData;
        }

        if ($mode === 'merge') {
            return $this->mergeIntoMpCoreGraph($processedData, $schemaNode);
        }

        if (!$this->shouldEmitStandaloneScript($request)) {
            return $processedData;
        }

        $targetVariable = trim((string)($processorConfiguration['as'] ?? 'vidplyMediaJsonLd'));
        if ($targetVariable === '') {
            $targetVariable = 'vidplyMediaJsonLd';
        }

        $json = $this->jsonLdBuilder->encodeStandaloneGraphNode($schemaNode);
        if ($json !== null && $json !== '') {
            $processedData[$targetVariable] = $json;
        }

        return $processedData;
    }

    /**
     * @param array{
     *     mode: 'single'|'list',
     *     pageUrl: string,
     *     pageName: string,
     *     items: list<array{
     *         media: array<string, mixed>,
     *         vidply: array<string, mixed>,
     *         pageUrl: string,
     *         itemUrl: string,
     *         posterUrl: string|null
     *     }>
     * } $structuredData
     * @return array<string, mixed>|null
     */
    private function buildSchemaNode(array $structuredData, ServerRequestInterface $request): ?array
    {
        if ($structuredData['mode'] === 'list') {
            return $this->jsonLdBuilder->buildItemListGraphNode(
                $request,
                $structuredData['pageUrl'],
                $structuredData['pageName'],
                $structuredData['items'],
            );
        }

        $item = $structuredData['items'][0] ?? null;
        if (!is_array($item)) {
            return null;
        }

        return $this->jsonLdBuilder->buildGraphNode(
            $item['media'],
            $request,
            $item['vidply'],
            $item['pageUrl'],
            $item['posterUrl'],
            null,
            $item['itemUrl'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $processedData
     * @param array<string, mixed> $schemaNode
     * @return array<string, mixed>
     */
    private function mergeIntoMpCoreGraph(array $processedData, array $schemaNode): array
    {
        $jsonLd = (string)($processedData['structuredDataJsonLd'] ?? '');
        if ($jsonLd !== '') {
            try {
                /** @var array<string, mixed> $payload */
                $payload = json_decode($jsonLd, true, 512, JSON_THROW_ON_ERROR);
                $graph = is_array($payload) ? ($payload['@graph'] ?? null) : null;
                if (is_array($graph)) {
                    /** @var list<array<string, mixed>> $graphList */
                    $graphList = array_values($graph);
                    $graphList[] = $schemaNode;
                    $graphList = $this->linkWebPageToMainEntity($graphList, (string)($schemaNode['@id'] ?? ''));

                    $payload['@graph'] = $graphList;
                    $processedData['structuredDataJsonLd'] = json_encode(
                        $payload,
                        MediaObjectJsonLdBuilder::JSON_ENCODE_FLAGS
                    );
                }

                // Parsed but without a @graph: leave mp-core's document untouched
                // rather than clobbering it.
                return $processedData;
            } catch (\Throwable) {
                // Invalid JSON: fall through to the standalone fallback below.
            }
        }

        // mp-core is loaded but produced no usable structured data (empty or invalid).
        // Emit a standalone JSON-LD document so the media still gets structured data
        // instead of being silently dropped.
        $standalone = $this->jsonLdBuilder->encodeStandaloneGraphNode($schemaNode);
        if ($standalone !== null && $standalone !== '') {
            $processedData['structuredDataJsonLd'] = $standalone;
        }

        return $processedData;
    }

    /**
     * When mp-core renders its own @graph, skip the standalone script to avoid duplicates.
     */
    private function shouldEmitStandaloneScript(ServerRequestInterface $request): bool
    {
        if (!ExtensionManagementUtility::isLoaded('mp_core')) {
            return true;
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return true;
        }

        return !filter_var(
            $site->getSettings()->get('structuredDataEnabled') ?? true,
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * @param list<array<string, mixed>> $graph
     * @return list<array<string, mixed>>
     */
    private function linkWebPageToMainEntity(array $graph, string $entityId): array
    {
        if ($entityId === '') {
            return $graph;
        }

        foreach ($graph as &$node) {
            $type = $node['@type'] ?? '';
            if ($type === 'WebPage' || $type === 'BlogPosting') {
                $node['mainEntity'] = ['@id' => $entityId];
                break;
            }
        }
        unset($node);

        return $graph;
    }
}
