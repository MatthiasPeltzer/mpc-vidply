<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\EventListener;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceScheme;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;

/**
 * Allows the external media hosts the editors may link to, after all static
 * CSP mutations are applied.
 *
 * Themes such as mp-core Set connect-src to 'self', which overrides earlier
 * Extend mutations from ContentSecurityPolicies.php. PolicyMutatedEvent runs
 * last, so HLS/DASH manifest and segment fetches (hls.js / dash.js XHR) and
 * directly linked media files keep working.
 *
 * Only the hosts from the `allowedVideoDomains` / `allowedAudioDomains`
 * allow-lists are added — never the whole `https:` scheme — so the extension
 * does not widen connect-src / media-src for every other script on the site.
 */
final readonly class StreamingContentSecurityPolicyEventListener
{
    private const DOMAIN_CONFIG_KEYS = ['allowedVideoDomains', 'allowedAudioDomains'];

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function __invoke(PolicyMutatedEvent $event): void
    {
        if (!$event->scope->type->isFrontend()) {
            return;
        }

        $sources = $this->buildAllowedMediaSources();
        $policy = $event->getCurrentPolicy()->extend(Directive::ConnectSrc, SourceScheme::blob, ...$sources);
        if ($sources !== []) {
            $policy = $policy->extend(Directive::MediaSrc, ...$sources);
        }

        $event->setCurrentPolicy($policy);
    }

    /**
     * @return list<UriValue>
     */
    public function buildAllowedMediaSources(): array
    {
        try {
            $config = $this->extensionConfiguration->get('mpc_vidply');
        } catch (\Throwable) {
            $config = [];
        }
        if (!is_array($config)) {
            return [];
        }

        $sources = [];
        foreach (self::DOMAIN_CONFIG_KEYS as $key) {
            $patterns = preg_split('/[,\r\n]+/', (string)($config[$key] ?? '')) ?: [];
            foreach ($patterns as $pattern) {
                foreach ($this->patternToSources(trim($pattern)) as $source) {
                    $sources[$source] = true;
                }
            }
        }

        return array_map(
            static fn (string $source): UriValue => new UriValue($source),
            array_keys($sources)
        );
    }

    /**
     * Map an allow-list pattern (see ExternalMediaDomainValidationTrait) to CSP
     * source expressions. `*.example.com` also allows `example.com` itself in
     * the allow-list, while the CSP wildcard does not, so both are emitted.
     *
     * @return list<string>
     */
    private function patternToSources(string $pattern): array
    {
        if ($pattern === '') {
            return [];
        }

        $schemes = ['https'];
        $host = strtolower($pattern);
        if (str_contains($host, '://')) {
            [$scheme, $host] = explode('://', $host, 2);
            if (!in_array($scheme, ['http', 'https'], true)) {
                return [];
            }
            $schemes = [$scheme];
        }
        $host = explode('/', $host, 2)[0];

        $wildcard = str_starts_with($host, '*.');
        $baseHost = $wildcard ? substr($host, 2) : $host;
        // Same guard as the allow-list: no bare TLD wildcards, only host characters.
        if (
            preg_match('/^[a-z0-9.-]+(:\d+)?$/', $baseHost) !== 1
            || ($wildcard && !str_contains($baseHost, '.'))
        ) {
            return [];
        }

        $sources = [];
        foreach ($schemes as $scheme) {
            $sources[] = $scheme . '://' . $baseHost;
            if ($wildcard) {
                $sources[] = $scheme . '://*.' . $baseHost;
            }
        }

        return $sources;
    }
}
