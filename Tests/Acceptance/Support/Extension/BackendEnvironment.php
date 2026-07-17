<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Acceptance\Support\Extension;

use TYPO3\TestingFramework\Core\Acceptance\Extension\BackendEnvironment as TestingFrameworkBackendEnvironment;

final class BackendEnvironment extends TestingFrameworkBackendEnvironment
{
    protected $localConfig = [
        'coreExtensionsToLoad' => [
            'typo3/cms-core',
            'typo3/cms-backend',
            'typo3/cms-frontend',
            'typo3/cms-extbase',
            'typo3/cms-fluid',
            'typo3/cms-install',
        ],
        'testExtensionsToLoad' => [
            'mpc/mpc-vidply',
        ],
        'csvDatabaseFixtures' => [
            __DIR__ . '/../../Fixtures/BackendEnvironment.csv',
        ],
        'configurationToUseInTestInstance' => [
            'SYS' => [
                'encryptionKey' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
            ],
            'GFX' => [
                'processor' => 'GraphicsMagick',
            ],
        ],
    ];
}
