<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Player;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reads a custom play icon from an `EXT:` path and returns it as sanitized
 * inline SVG, so the privacy layer can print it without a second HTTP request.
 *
 * Everything that could execute, animate or phone home is removed: an icon is
 * editable configuration, and it is rendered unescaped.
 */
final class InlineSvgProvider
{
    /** Icons are decoration; anything larger is not one and is not read. */
    private const MAX_BYTES = 256 * 1024;

    private const STRIPPED_ELEMENTS = [
        'script',
        'foreignObject',
        'style',
        'animate',
        'set',
        'animateTransform',
        'animateMotion',
    ];

    public function fromExtensionPath(string $extPathOrUrl): ?string
    {
        $value = trim($extPathOrUrl);
        if ($value === '' || !str_starts_with($value, 'EXT:')) {
            return null;
        }
        if (strtolower((string)pathinfo($value, PATHINFO_EXTENSION)) !== 'svg') {
            return null;
        }

        $normalized = preg_replace_callback(
            '/^EXT:([a-z0-9-]+)\\//i',
            static function (array $m): string {
                return 'EXT:' . str_replace('-', '_', $m[1]) . '/';
            },
            $value
        ) ?: $value;

        $abs = GeneralUtility::getFileAbsFileName($normalized);
        if (!$abs) {
            $abs = GeneralUtility::getFileAbsFileName($value);
        }

        return $abs ? $this->fromAbsolutePath((string)$abs) : null;
    }

    public function fromAbsolutePath(string $absolutePath): ?string
    {
        $absolutePath = trim($absolutePath);
        if ($absolutePath === '' || !is_file($absolutePath)) {
            return null;
        }

        $size = @filesize($absolutePath);
        if (is_int($size) && $size > self::MAX_BYTES) {
            return null;
        }

        $raw = @file_get_contents($absolutePath);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $loaded = @$dom->loadXML($raw, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        if (!$loaded) {
            return null;
        }

        $svg = $dom->getElementsByTagName('svg')->item(0);
        if (!$svg instanceof \DOMElement) {
            return null;
        }

        foreach (self::STRIPPED_ELEMENTS as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                if ($node && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $this->restrictExternalReferences($dom);
        $this->stripUnsafeAttributes($dom);

        $existingClass = trim((string)$svg->getAttribute('class'));
        $parts = preg_split('/\s+/', $existingClass) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $c): bool => $c !== '' && $c !== 'vidply-play-overlay'));
        $parts[] = 'mpc-vidply-custom-play-icon';
        $svg->setAttribute('class', implode(' ', array_values(array_unique($parts))));
        $svg->setAttribute('aria-hidden', 'true');
        $svg->setAttribute('focusable', 'false');
        $svg->removeAttribute('width');
        $svg->removeAttribute('height');

        return $dom->saveXML($svg) ?: null;
    }

    /**
     * Drop `on*` handlers and `javascript:`/`data:` hrefs anywhere in the tree.
     */
    private function stripUnsafeAttributes(\DOMDocument $dom): void
    {
        $xpath = new \DOMXPath($dom);

        $attrs = $xpath->query('//@*');
        if (!$attrs instanceof \DOMNodeList) {
            return;
        }

        for ($i = $attrs->length - 1; $i >= 0; $i--) {
            $attr = $attrs->item($i);
            if (!$attr instanceof \DOMAttr) {
                continue;
            }
            $name = strtolower($attr->name);
            if (str_starts_with($name, 'on')) {
                $attr->ownerElement?->removeAttributeNode($attr);
                continue;
            }

            if ($attr->localName === 'href') {
                $val = trim((string)$attr->value);
                if (preg_match('/^(javascript|data):/i', $val)) {
                    $attr->ownerElement?->removeAttributeNode($attr);
                }
            }
        }
    }

    /**
     * Strip <image> elements and restrict <use> href to local fragment references (#id)
     * to prevent SSRF via external resource loading in inline SVGs.
     */
    private function restrictExternalReferences(\DOMDocument $dom): void
    {
        $imageNodes = $dom->getElementsByTagName('image');
        for ($i = $imageNodes->length - 1; $i >= 0; $i--) {
            $node = $imageNodes->item($i);
            if ($node && $node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }

        $useNodes = $dom->getElementsByTagName('use');
        for ($i = $useNodes->length - 1; $i >= 0; $i--) {
            $node = $useNodes->item($i);
            if (!$node instanceof \DOMElement || !$node->parentNode) {
                continue;
            }
            $href = $node->getAttribute('href') ?: $node->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
            if ($href !== '' && !str_starts_with(trim($href), '#')) {
                $node->parentNode->removeChild($node);
            }
        }
    }
}
