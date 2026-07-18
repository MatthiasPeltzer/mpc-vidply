<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use TYPO3\CMS\Core\MetaTag\MetaTagManagerRegistry;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\ImageService;

/**
 * Overrides the description and OpenGraph/Twitter meta tags on a VidPly detail
 * view so search snippets and social previews use the media element's own title,
 * description and poster instead of the (generic) detail page record.
 *
 * This complements {@see \Mpc\MpcVidply\PageTitle\VidPlyDetailPageTitleProvider},
 * which already sets the HTML <title>.
 *
 * Ordering with EXT:seo:
 *   - DataProcessors run before EXT:seo's meta tag hook, and the MetaTag API
 *     keeps the first value for single-value properties (description,
 *     og:title/description, twitter:title/description/image). Setting those in
 *     {@see applyForMedia()} therefore wins over the page-record values.
 *   - `og:image` allows multiple occurrences, so a value added before EXT:seo
 *     would not replace the page image but sit next to it. It is therefore
 *     deferred to {@see replaceOpenGraphImage()}, registered as a
 *     `generateMetaTags` handler in ext_localconf.php that runs after EXT:seo.
 *
 * Registered as a singleton so the poster resolved during the detail data flow
 * is still available to the deferred `og:image` handler within the same request.
 */
final class DetailMetaTagService implements SingletonInterface
{
    /**
     * Soft cap for the generated description; long descriptions are truncated on
     * a word boundary to keep social/search snippets sensible.
     */
    private const DESCRIPTION_MAX_LENGTH = 160;

    private readonly MetaTagManagerRegistry $metaTagManagerRegistry;
    private readonly ImageService $imageService;

    /**
     * OpenGraph image to emit once EXT:seo has run.
     *
     * @var array{url: string, width: int, height: int, alt: string}|null
     */
    private ?array $pendingOpenGraphImage = null;

    public function __construct(
        ?MetaTagManagerRegistry $metaTagManagerRegistry = null,
        ?ImageService $imageService = null
    ) {
        $this->metaTagManagerRegistry = $metaTagManagerRegistry
            ?? GeneralUtility::makeInstance(MetaTagManagerRegistry::class);
        $this->imageService = $imageService ?? GeneralUtility::makeInstance(ImageService::class);
    }

    /**
     * @param array<string, mixed> $media
     */
    public function applyForMedia(array $media, ?FileReference $poster = null): void
    {
        $title = trim((string)($media['title'] ?? ''));
        $description = $this->buildDescription($media);

        if ($title !== '') {
            $this->setProperty('og:title', $title);
            $this->setProperty('twitter:title', $title);
        }

        if ($description !== '') {
            $this->setProperty('description', $description);
            $this->setProperty('og:description', $description);
            $this->setProperty('twitter:description', $description);
        }

        $image = $poster !== null ? $this->buildImage($poster, $title) : null;
        if ($image === null) {
            return;
        }

        // twitter:image is a single-value property → set it now so it wins over
        // the page-record image EXT:seo would add afterwards.
        $this->setProperty('twitter:image', $image['url'], $image['alt'] !== '' ? ['alt' => $image['alt']] : []);

        // og:image allows multiple occurrences → defer until after EXT:seo so we
        // can replace the page-record image instead of emitting a second tag.
        $this->pendingOpenGraphImage = $image;
    }

    /**
     * Registered as a `generateMetaTags` handler (ext_localconf.php) that runs
     * after EXT:seo, replacing the OpenGraph image with the media poster.
     *
     * @param array<string, mixed> $params
     */
    public function replaceOpenGraphImage(array $params): void
    {
        if ($this->pendingOpenGraphImage === null) {
            return;
        }

        $image = $this->pendingOpenGraphImage;
        $this->pendingOpenGraphImage = null;

        $subProperties = ['url' => $image['url']];
        if ($image['width'] > 0) {
            $subProperties['width'] = (string)$image['width'];
        }
        if ($image['height'] > 0) {
            $subProperties['height'] = (string)$image['height'];
        }
        if ($image['alt'] !== '') {
            $subProperties['alt'] = $image['alt'];
        }

        $manager = $this->metaTagManagerRegistry->getManagerForProperty('og:image');
        $manager->removeProperty('og:image');
        $manager->addProperty('og:image', $image['url'], $subProperties);
    }

    /**
     * Prefer the short description; fall back to the (stripped) long description.
     *
     * @param array<string, mixed> $media
     */
    private function buildDescription(array $media): string
    {
        $description = trim((string)($media['description'] ?? ''));
        if ($description === '') {
            $description = (string)($media['long_description'] ?? '');
        }

        $description = trim((string)preg_replace('/\s+/', ' ', strip_tags($description)));
        if ($description === '') {
            return '';
        }

        if (mb_strlen($description) <= self::DESCRIPTION_MAX_LENGTH) {
            return $description;
        }

        $truncated = mb_substr($description, 0, self::DESCRIPTION_MAX_LENGTH);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated) . '…';
    }

    /**
     * Build an absolute poster URL (plus dimensions and alt text) the same way
     * EXT:seo builds its social images.
     *
     * @return array{url: string, width: int, height: int, alt: string}|null
     */
    private function buildImage(FileReference $poster, string $fallbackAlt): ?array
    {
        try {
            $url = trim($this->imageService->getImageUri($poster, true));
        } catch (\Throwable) {
            return null;
        }

        if ($url === '') {
            return null;
        }

        $alt = trim((string)$poster->getAlternative());
        if ($alt === '') {
            $alt = $fallbackAlt;
        }

        return [
            'url' => $url,
            'width' => (int)$poster->getProperty('width'),
            'height' => (int)$poster->getProperty('height'),
            'alt' => $alt,
        ];
    }

    /**
     * @param array<string, string> $subProperties
     */
    private function setProperty(string $property, string $content, array $subProperties = []): void
    {
        $this->metaTagManagerRegistry
            ->getManagerForProperty($property)
            ->addProperty($property, $content, $subProperties, true);
    }
}
