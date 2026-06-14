<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\EventListener;

use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceScheme;

/**
 * Extends connect-src after all static CSP mutations are applied.
 *
 * Themes such as mp-core Set connect-src to 'self', which overrides earlier
 * Extend mutations from ContentSecurityPolicies.php. PolicyMutatedEvent runs
 * last, so HLS/DASH manifest and segment fetches (hls.js / dash.js XHR) work.
 */
final readonly class StreamingContentSecurityPolicyEventListener
{
    public function __invoke(PolicyMutatedEvent $event): void
    {
        if (!$event->scope->type->isFrontend()) {
            return;
        }

        $event->setCurrentPolicy(
            $event->getCurrentPolicy()->extend(
                Directive::ConnectSrc,
                SourceScheme::blob,
                SourceScheme::https,
            )
        );
    }
}
