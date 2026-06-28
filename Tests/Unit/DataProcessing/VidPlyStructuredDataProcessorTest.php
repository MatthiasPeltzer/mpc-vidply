<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\DataProcessing;

use Mpc\MpcVidply\DataProcessing\VidPlyStructuredDataProcessor;
use Mpc\MpcVidply\Service\MediaObjectJsonLdBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the array-level merge/fallback logic of {@see VidPlyStructuredDataProcessor}.
 *
 * The processor's collaborators ({@see \Mpc\MpcVidply\Service\VidPlyPageMediaResolver}
 * and {@see MediaObjectJsonLdBuilder}) are final and DB/request bound, so the subject is
 * instantiated without its constructor and the pure helpers are reached via reflection.
 * The (pure) JSON-LD builder is injected so the standalone fallback can encode output.
 */
final class VidPlyStructuredDataProcessorTest extends TestCase
{
    private VidPlyStructuredDataProcessor $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new \ReflectionClass(VidPlyStructuredDataProcessor::class);
        $this->subject = $reflection->newInstanceWithoutConstructor();

        $builderProperty = $reflection->getProperty('jsonLdBuilder');
        $builderProperty->setValue($this->subject, new MediaObjectJsonLdBuilder());
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(VidPlyStructuredDataProcessor::class, $method))
            ->invoke($this->subject, ...$args);
    }

    #[Test]
    public function mergeAppendsNodeToExistingGraphAndLinksWebPageMainEntity(): void
    {
        $existing = json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'WebPage', '@id' => 'https://example.com/videos#webpage'],
            ],
        ]);
        $schemaNode = [
            '@type' => 'VideoObject',
            '@id' => 'https://example.com/videos#media',
            'name' => 'Clip',
        ];

        $result = $this->invoke('mergeIntoMpCoreGraph', ['structuredDataJsonLd' => $existing], $schemaNode);

        $payload = json_decode($result['structuredDataJsonLd'], true);
        self::assertCount(2, $payload['@graph']);
        self::assertSame('VideoObject', $payload['@graph'][1]['@type']);
        self::assertSame(
            ['@id' => 'https://example.com/videos#media'],
            $payload['@graph'][0]['mainEntity']
        );
    }

    #[Test]
    public function mergeFallsBackToStandaloneWhenGraphIsEmpty(): void
    {
        $schemaNode = ['@type' => 'VideoObject', 'name' => 'Clip'];

        $result = $this->invoke('mergeIntoMpCoreGraph', ['structuredDataJsonLd' => ''], $schemaNode);

        self::assertArrayHasKey('structuredDataJsonLd', $result);
        self::assertStringContainsString('"@context":"https://schema.org"', $result['structuredDataJsonLd']);
        self::assertStringContainsString('"VideoObject"', $result['structuredDataJsonLd']);
    }

    #[Test]
    public function mergeFallsBackToStandaloneWhenExistingJsonIsInvalid(): void
    {
        $schemaNode = ['@type' => 'VideoObject', 'name' => 'Clip'];

        $result = $this->invoke('mergeIntoMpCoreGraph', ['structuredDataJsonLd' => '{not json'], $schemaNode);

        self::assertStringContainsString('"@context":"https://schema.org"', $result['structuredDataJsonLd']);
    }

    #[Test]
    public function mergeLeavesDocumentUntouchedWhenValidJsonHasNoGraph(): void
    {
        $existing = json_encode(['@context' => 'https://schema.org', '@type' => 'Organization']);
        $schemaNode = ['@type' => 'VideoObject', 'name' => 'Clip'];

        $result = $this->invoke('mergeIntoMpCoreGraph', ['structuredDataJsonLd' => $existing], $schemaNode);

        self::assertSame($existing, $result['structuredDataJsonLd']);
    }

    #[Test]
    public function linkWebPageToMainEntityTargetsFirstWebPageOrBlogPosting(): void
    {
        $graph = [
            ['@type' => 'Organization'],
            ['@type' => 'BlogPosting', '@id' => 'https://example.com/post#blog'],
        ];

        $linked = $this->invoke('linkWebPageToMainEntity', $graph, 'https://example.com/post#media');

        self::assertArrayNotHasKey('mainEntity', $linked[0]);
        self::assertSame(['@id' => 'https://example.com/post#media'], $linked[1]['mainEntity']);
    }
}
