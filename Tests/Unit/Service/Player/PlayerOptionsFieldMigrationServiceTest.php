<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service\Player;

use Mpc\MpcVidply\Service\Player\PlayerOptionsFieldMigrationService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PlayerOptionsFieldMigrationServiceTest extends UnitTestCase
{
    private PlayerOptionsFieldMigrationService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new PlayerOptionsFieldMigrationService();
    }

    #[Test]
    public function migrateRecordMovesLegacyBitmaskBitsIntoDedicatedFields(): void
    {
        $result = $this->subject->migrateRecord(1404);

        self::assertSame(1, $result['showTrackInfo']);
        self::assertSame(0, $result['resumePlayback']);
        self::assertSame(124, $result['options']);
    }

    #[Test]
    public function migrateRecordPreservesExistingDedicatedFieldValues(): void
    {
        $result = $this->subject->migrateRecord(124, 1, 0);

        self::assertSame(1, $result['showTrackInfo']);
        self::assertSame(0, $result['resumePlayback']);
        self::assertSame(124, $result['options']);
    }

    #[Test]
    public function migrateRecordMovesResumePlaybackFromLegacyBitmask(): void
    {
        $result = $this->subject->migrateRecord(124 + 512);

        self::assertSame(0, $result['showTrackInfo']);
        self::assertSame(1, $result['resumePlayback']);
        self::assertSame(124, $result['options']);
    }

    #[Test]
    public function migrateRecordRemapsLegacyKeyboardAndAutoAdvanceBits(): void
    {
        // Old default: controls (8) + keyboard (64) + auto-advance (256) = 328
        $result = $this->subject->migrateRecord(328);

        self::assertSame(104, $result['options']);
        self::assertTrue(($result['options'] & 32) !== 0);
        self::assertTrue(($result['options'] & 64) !== 0);
    }

    #[Test]
    public function migrateRecordKeepsPositionalKeyboardWhenTrackInfoAlreadyDedicated(): void
    {
        // FormEngine save with keyboard checked (bit 32) while track info lives in its own field.
        $result = $this->subject->migrateRecord(60, 1, 0);

        self::assertSame(1, $result['showTrackInfo']);
        self::assertSame(60, $result['options']);
    }
}
