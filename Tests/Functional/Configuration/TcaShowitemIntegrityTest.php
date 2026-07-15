<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\Configuration;

use Mpc\MpcVidply\Tests\Support\MpcVidplyTcaManifest;
use Mpc\MpcVidply\Tests\Support\TcaShowitemInspector;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TcaShowitemIntegrityTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = MpcVidplyTcaManifest::FUNCTIONAL_TEST_EXTENSIONS;

    private TcaShowitemInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inspector = new TcaShowitemInspector();
    }

    #[Test]
    public function vidplyContentTypesHaveValidShowitemReferences(): void
    {
        $violations = [];

        foreach (MpcVidplyTcaManifest::C_TYPES as $cType) {
            $violations = [
                ...$violations,
                ...$this->inspector->inspectTableType('tt_content', $cType),
            ];
        }

        self::assertSame([], $violations, $this->formatViolations($violations));
    }

    #[Test]
    public function customTablesHaveValidShowitemReferences(): void
    {
        $violations = [];

        foreach (MpcVidplyTcaManifest::CUSTOM_TABLES as $table) {
            $types = array_keys($GLOBALS['TCA'][$table]['types'] ?? []);
            foreach ($types as $typeName) {
                $violations = [
                    ...$violations,
                    ...$this->inspector->inspectTableType($table, (string)$typeName),
                ];
            }
        }

        self::assertSame([], $violations, $this->formatViolations($violations));
    }

    /**
     * @param list<string> $violations
     */
    private function formatViolations(array $violations): string
    {
        if ($violations === []) {
            return '';
        }

        return "TCA showitem integrity violations:\n- " . implode("\n- ", $violations);
    }
}
