<?php

declare(strict_types=1);

/**
 * Bootstrap for the mpc_vidply unit test suite.
 *
 * The extension can be tested either standalone (its own `composer install`)
 * or from inside the mpcore monorepo where it is symlinked into the project's
 * vendor directory via a Composer path repository. We therefore probe the
 * common Composer autoload locations and use the first one that exists.
 */
$autoloadCandidates = [
    // Standalone: `composer install` inside the extension.
    __DIR__ . '/../vendor/autoload.php',
    // Standalone with a `.Build` web layout (typo3/cms-composer-installers).
    __DIR__ . '/../.Build/vendor/autoload.php',
    // mpcore monorepo: extension lives at <root>/libs/mpc-vidply.
    __DIR__ . '/../../../vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;

        return;
    }
}

fwrite(
    STDERR,
    "Unable to locate a Composer autoload.php for the mpc_vidply test suite.\n"
    . "Run `composer install` in the extension, or run the suite from the mpcore monorepo.\n"
);
exit(1);
