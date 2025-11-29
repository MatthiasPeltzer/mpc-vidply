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
 * This allows blob: URLs which are required for HLS streaming via hls.js
 */
return Map::fromEntries([
    // Frontend scope
    Scope::frontend(),
    new MutationCollection(
        // Add blob: to default-src as a base
        new Mutation(
            MutationMode::Extend,
            Directive::DefaultSrc,
            SourceScheme::blob
        ),
        
        // Allow blob: for media sources (required for HLS streaming)
        new Mutation(
            MutationMode::Extend,
            Directive::MediaSrc,
            SourceScheme::blob,
            SourceScheme::data,
            SourceScheme::https
        ),
        
        // Allow blob: for worker sources (required for hls.js web workers)
        new Mutation(
            MutationMode::Extend,
            Directive::WorkerSrc,
            SourceScheme::blob
        ),
        
        // Allow blob: for connect/fetch (required for HLS blob URL loading)
        new Mutation(
            MutationMode::Set,
            Directive::ConnectSrc,
            SourceKeyword::self,
            SourceScheme::blob,
            SourceScheme::data,
            SourceScheme::https
        ),
        
        // Allow blob: for object-src (required for Firefox blob URL handling)
        new Mutation(
            MutationMode::Set,
            Directive::ObjectSrc,
            SourceKeyword::self,
            SourceScheme::blob
        ),
        
        // Allow blob: for child-src (Firefox security requirement)
        new Mutation(
            MutationMode::Set,
            Directive::ChildSrc,
            SourceKeyword::self,
            SourceScheme::blob
        ),
        
        // Allow unsafe-inline for scripts (if needed)
        new Mutation(
            MutationMode::Extend,
            Directive::ScriptSrc,
            SourceKeyword::unsafeInline,
            new UriValue('https://cdn.jsdelivr.net')
        ),
        
        // Allow script elements from CDN (for hls.js dynamic loading)
        new Mutation(
            MutationMode::Extend,
            Directive::ScriptSrcElem,
            new UriValue('https://cdn.jsdelivr.net')
        ),
        
        // Allow unsafe-inline for styles
        new Mutation(
            MutationMode::Extend,
            Directive::StyleSrc,
            SourceKeyword::unsafeInline
        ),
        
        // Allow unsafe-inline for style elements
        new Mutation(
            MutationMode::Extend,
            Directive::StyleSrcElem,
            SourceKeyword::unsafeInline
        ),
    ),
]);

