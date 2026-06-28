<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Unit\Form\FormDataProvider;

use Mpc\MpcVidply\Form\FormDataProvider\MediaUrlImportFormDataProvider;
use Mpc\MpcVidply\Service\MediaUrlImportSessionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

#[CoversClass(MediaUrlImportFormDataProvider::class)]
final class MediaUrlImportFormDataProviderTest extends TestCase
{
    private BackendUserAuthentication $backendUser;

    private MediaUrlImportSessionService $sessionService;

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
        $this->sessionService = new MediaUrlImportSessionService();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function addDataAppliesPendingImportOnlyToEmptyTextFields(): void
    {
        $this->sessionService->store('tx_mpcvidply_media:NEW123', [
            'mediaType' => 'youtube',
            'mediaFileUid' => 42,
            'title' => 'Imported title',
            'artist' => 'Imported artist',
            'posterFileUid' => 99,
            'detectedLabel' => 'YouTube',
        ]);

        $subject = new MediaUrlImportFormDataProvider($this->sessionService);
        $result = $subject->addData([
            'tableName' => 'tx_mpcvidply_media',
            'databaseRow' => [
                'uid' => 'NEW123',
                'title' => 'Existing title',
                'artist' => '',
            ],
        ]);

        self::assertSame('youtube', $result['databaseRow']['media_type']);
        self::assertSame('Existing title', $result['databaseRow']['title']);
        self::assertSame('Imported artist', $result['databaseRow']['artist']);
        self::assertSame(
            [
                'mediaType' => 'youtube',
                'mediaFileUid' => 42,
                'posterFileUid' => 99,
                'title' => 'Imported title',
                'artist' => 'Imported artist',
            ],
            $result['customData']['mpcVidplyPendingImport']
        );
        self::assertSame('YouTube', $result['customData']['mpcVidplyImportLabel']);
        self::assertSame([], $this->sessionStorage['mpc_vidply_pending_import'] ?? []);
    }

    #[Test]
    public function addDataResolvesArrayUidFromFormEngine(): void
    {
        $this->sessionService->store('tx_mpcvidply_media:NEW456', [
            'mediaType' => 'youtube',
            'mediaFileUid' => 7,
        ]);

        $subject = new MediaUrlImportFormDataProvider($this->sessionService);
        $result = $subject->addData([
            'tableName' => 'tx_mpcvidply_media',
            'databaseRow' => [
                'uid' => ['NEW456'],
                'title' => '',
            ],
        ]);

        self::assertSame('youtube', $result['databaseRow']['media_type']);
        self::assertSame(['mediaType' => 'youtube', 'mediaFileUid' => 7], $result['customData']['mpcVidplyPendingImport']);
    }

    #[Test]
    public function addDataIgnoresOtherTables(): void
    {
        $this->sessionService->store('tx_mpcvidply_media:1', [
            'mediaType' => 'youtube',
        ]);

        $subject = new MediaUrlImportFormDataProvider($this->sessionService);
        $result = $subject->addData([
            'tableName' => 'tt_content',
            'databaseRow' => ['uid' => 1],
        ]);

        self::assertSame('tt_content', $result['tableName']);
        self::assertArrayNotHasKey('media_type', $result['databaseRow']);
        self::assertSame(
            ['tx_mpcvidply_media:1' => ['mediaType' => 'youtube']],
            $this->sessionStorage['mpc_vidply_pending_import'] ?? null
        );
    }
}
