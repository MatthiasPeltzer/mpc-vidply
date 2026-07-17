<?php

$config = \TYPO3\CodingStandards\CsFixerConfig::create();
$config->setUnsupportedPhpVersionAllowed(true);
$rules = $config->getRules();
unset($rules['@PER-CS1.0']);
$rules['@PER-CS1x0'] = true;
$config->setRules($rules);
$config->getFinder()
    ->in(__DIR__)
    ->exclude([
        'node_modules',
        'vendor',
        '.Build',
        'Resources/Vendor',
        'Tests/Acceptance/Support/_generated',
    ])
;

return $config;
