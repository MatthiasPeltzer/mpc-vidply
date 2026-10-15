<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional;

use Mpc\MpcVidply\Tests\Support\MpcVidplyTcaManifest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase as Typo3FunctionalTestCase;

/**
 * Shared functional test bootstrap for mpc-vidply.
 *
 * Standalone CI installs typo3/cms-install as a dev dependency; the functional
 * instance still needs the install sysext in PackageStates on TYPO3 14.3.
 */
abstract class MpcVidplyFunctionalTestCase extends Typo3FunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = MpcVidplyTcaManifest::CORE_EXTENSIONS_TO_LOAD;
}
