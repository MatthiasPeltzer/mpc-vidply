<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Utility;

use Mpc\MpcVidply\Utility\RecordAwareValueResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordAwareValueResolver::class)]
final class RecordAwareValueResolverTest extends TestCase
{
    #[Test]
    public function normalizeToArrayReturnsPlainArray(): void
    {
        $row = ['uid' => 1, 'title' => 'Test'];

        self::assertSame($row, RecordAwareValueResolver::normalizeToArray($row));
    }

    #[Test]
    public function normalizeToArrayCallsToArrayOnRecordLikeObject(): void
    {
        $record = new class () {
            /**
             * @return array<string, int>
             */
            public function toArray(): array
            {
                return ['uid' => 42];
            }
        };

        self::assertSame(['uid' => 42], RecordAwareValueResolver::normalizeToArray($record));
    }

    #[Test]
    public function resolveScalarHandlesFormEngineArrayUid(): void
    {
        self::assertSame('NEW456', RecordAwareValueResolver::resolveScalar(['NEW456']));
    }

    #[Test]
    public function resolveScalarHandlesNestedValueArray(): void
    {
        self::assertSame('7', RecordAwareValueResolver::resolveScalar([['value' => '7']]));
    }

    #[Test]
    public function resolveScalarHandlesRecordObjectWithGetUid(): void
    {
        $record = new class () {
            public function getUid(): int
            {
                return 99;
            }
        };

        self::assertSame('99', RecordAwareValueResolver::resolveScalar($record));
    }

    #[Test]
    public function resolveScalarHandlesRecordObjectWithToArray(): void
    {
        $record = new class () {
            /**
             * @return array<string, int>
             */
            public function toArray(): array
            {
                return ['uid' => 12];
            }
        };

        self::assertSame('12', RecordAwareValueResolver::resolveScalar($record));
    }

    #[Test]
    public function resolveIntReturnsDefaultForEmptyValue(): void
    {
        self::assertSame(0, RecordAwareValueResolver::resolveInt(null));
        self::assertSame(5, RecordAwareValueResolver::resolveInt('', 5));
    }
}
