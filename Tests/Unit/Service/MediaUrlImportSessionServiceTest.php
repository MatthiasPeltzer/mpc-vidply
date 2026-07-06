<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Service;

use Mpc\MpcVidply\Service\MediaUrlImportSessionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

#[CoversClass(MediaUrlImportSessionService::class)]
final class MediaUrlImportSessionServiceTest extends TestCase
{
    private BackendUserAuthentication $backendUser;

    private MediaUrlImportSessionService $subject;

    /** @var array<string, mixed> */
    private array $sessionStorage = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionStorage = [];
        $this->backendUser = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSessionData', 'setSessionData'])
            ->getMock();
        $this->backendUser->method('getSessionData')->willReturnCallback(
            fn (string $key): mixed => $this->sessionStorage[$key] ?? null
        );
        $this->backendUser->method('setSessionData')->willReturnCallback(function (string $key, mixed $data): void {
            $this->sessionStorage[$key] = $data;
        });
        $GLOBALS['BE_USER'] = $this->backendUser;
        $this->subject = new MediaUrlImportSessionService();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function storeAndPullRemovesPendingImport(): void
    {
        $this->subject->store('tx_mpcvidply_media:NEW1', ['mediaType' => 'vimeo']);

        self::assertSame(
            ['mediaType' => 'vimeo'],
            $this->subject->pull('tx_mpcvidply_media:NEW1')
        );
        self::assertNull($this->subject->pull('tx_mpcvidply_media:NEW1'));
    }

    #[Test]
    public function storeKeepsPosterUidAvailableForSaveAfterPull(): void
    {
        $this->subject->store('tx_mpcvidply_media:NEW1', [
            'mediaType' => 'youtube',
            'posterFileUid' => 99,
        ]);

        self::assertSame(['mediaType' => 'youtube', 'posterFileUid' => 99], $this->subject->pull('tx_mpcvidply_media:NEW1'));
        self::assertSame(99, $this->subject->resolvePosterFileUidForSave('tx_mpcvidply_media:NEW1'));
        self::assertSame(0, $this->subject->resolvePosterFileUidForSave('tx_mpcvidply_media:NEW1'));
    }

    #[Test]
    public function buildRecordKeyUsesTableAndUid(): void
    {
        self::assertSame(
            'tx_mpcvidply_media:NEW123',
            $this->subject->buildRecordKey('tx_mpcvidply_media', 'NEW123')
        );
    }
}
