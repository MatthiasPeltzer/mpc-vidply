<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service;

use Mpc\MpcVidply\Service\FrontendLanguageResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(FrontendLanguageResolver::class)]
final class FrontendLanguageResolverTest extends TestCase
{
    #[Test]
    public function resolveLanguageIdUsesRequestLanguageWhenPositive(): void
    {
        $request = $this->createRequestWithLanguage(2);

        self::assertSame(
            2,
            FrontendLanguageResolver::resolveLanguageId($request, ['sys_language_uid' => 1])
        );
    }

    #[Test]
    public function resolveLanguageIdFallsBackToContentElementLanguage(): void
    {
        $request = $this->createRequestWithLanguage(0);

        self::assertSame(
            3,
            FrontendLanguageResolver::resolveLanguageId($request, ['sys_language_uid' => 3])
        );
    }

    #[Test]
    public function resolveLanguageIdReturnsZeroWithoutFallbackData(): void
    {
        $request = $this->createRequestWithLanguage(0);

        self::assertSame(0, FrontendLanguageResolver::resolveLanguageId($request));
    }

    private function createRequestWithLanguage(int $languageId): ServerRequestInterface
    {
        $language = new class ($languageId) {
            public function __construct(private readonly int $languageId) {}

            /**
             * @return array<string, int>
             */
            public function toArray(): array
            {
                return ['languageId' => $this->languageId];
            }
        };

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('language')->willReturn($language);

        return $request;
    }
}
