<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\PageTitle;

use Mpc\MpcVidply\Service\DetailRequestResolver;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\PageTitle\PageTitleProviderInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Overrides the HTML <title> tag when the current request resolves to a VidPly
 * detail view. The provider returns the media title so it takes precedence over
 * the page-record title produced by core providers.
 *
 * Registered in ext_localconf.php with `before: [seo, record]` so a missing
 * media record simply returns an empty string and the default chain wins.
 */
final class VidPlyDetailPageTitleProvider implements PageTitleProviderInterface
{
    private readonly DetailRequestResolver $resolver;
    private ?ServerRequestInterface $request = null;

    public function __construct(?DetailRequestResolver $resolver = null)
    {
        $this->resolver = $resolver ?? GeneralUtility::makeInstance(DetailRequestResolver::class);
    }

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function getTitle(): string
    {
        $request = $this->request ?? ($GLOBALS['TYPO3_REQUEST'] ?? null);
        if (!$request instanceof ServerRequestInterface) {
            return '';
        }

        $media = $this->resolver->resolveFromRequest($request);
        if ($media === null) {
            return '';
        }

        return trim((string)($media['title'] ?? ''));
    }
}
