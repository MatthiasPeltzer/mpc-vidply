<?php

declare(strict_types=1);

/**
 * Bootstrap for the mpc_vidply functional test suite.
 *
 * Adapted from typo3/testing-framework's FunctionalTestsBootstrap.php. It is
 * referenced from Build/FunctionalTests.xml and executed by PHPUnit before the
 * test suites are instantiated.
 */
(static function (): void {
    $testbase = new \TYPO3\TestingFramework\Core\Testbase();
    $testbase->defineOriginalRootPath();
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
})();
