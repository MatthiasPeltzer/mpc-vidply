<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceScheme;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Type\Map;

/**
 * Content Security Policy configuration for VidPly
 *
 * Allows blob: URLs required for HLS streaming via hls.js and
 * external embed origins for YouTube, Vimeo, and SoundCloud.
 */
return Map::fromEntries([
    Scope::frontend(),
    new MutationCollection(
        // blob: for media sources (HLS streaming segments)
        new Mutation(
            MutationMode::Extend,
            Directive::MediaSrc,
            SourceScheme::blob,
            SourceScheme::https
        ),

        // blob: for worker sources (hls.js web workers)
        new Mutation(
            MutationMode::Extend,
            Directive::WorkerSrc,
            SourceScheme::blob
        ),

        // blob: + https for fetch/XHR (HLS segment loading)
        new Mutation(
            MutationMode::Extend,
            Directive::ConnectSrc,
            SourceScheme::blob,
            SourceScheme::https
        ),

        // blob: for object-src (Firefox blob URL handling)
        new Mutation(
            MutationMode::Extend,
            Directive::ObjectSrc,
            SourceScheme::blob
        ),

        // blob: for child-src (Firefox security requirement)
        new Mutation(
            MutationMode::Extend,
            Directive::ChildSrc,
            SourceScheme::blob
        ),

        // Script origins for CDN and embed providers (no unsafe-inline)
        new Mutation(
            MutationMode::Extend,
            Directive::ScriptSrc,
            new UriValue('https://cdn.jsdelivr.net'),
            new UriValue('https://*.youtube.com'),
            new UriValue('https://*.youtube-nocookie.com'),
            new UriValue('https://*.vimeo.com'),
            new UriValue('https://*.soundcloud.com')
        ),

        // Script element sources for hls.js CDN loading
        new Mutation(
            MutationMode::Extend,
            Directive::ScriptSrcElem,
            new UriValue('https://cdn.jsdelivr.net'),
            new UriValue('https://*.youtube.com'),
            new UriValue('https://*.youtube-nocookie.com'),
            new UriValue('https://*.vimeo.com'),
            new UriValue('https://*.soundcloud.com')
        ),

        // Inline styles required by third-party embed iframes
        new Mutation(
            MutationMode::Extend,
            Directive::StyleSrc,
            SourceKeyword::unsafeInline,
            new UriValue('https://*.youtube.com'),
            new UriValue('https://*.youtube-nocookie.com'),
            new UriValue('https://*.vimeo.com'),
            new UriValue('https://*.soundcloud.com')
        ),

        new Mutation(
            MutationMode::Extend,
            Directive::StyleSrcElem,
            SourceKeyword::unsafeInline,
            new UriValue('https://*.youtube.com'),
            new UriValue('https://*.youtube-nocookie.com'),
            new UriValue('https://*.vimeo.com'),
            new UriValue('https://*.soundcloud.com')
        ),

        // External embed iframes (YouTube, Vimeo, SoundCloud)
        new Mutation(
            MutationMode::Extend,
            Directive::FrameSrc,
            SourceKeyword::self,
            new UriValue('https://*.youtube.com'),
            new UriValue('https://*.youtube-nocookie.com'),
            new UriValue('https://*.vimeo.com'),
            new UriValue('https://*.soundcloud.com')
        ),
    ),
]);
