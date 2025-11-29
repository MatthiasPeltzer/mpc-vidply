<?php

defined('TYPO3') or die();

return [
    'dependencies' => ['backend'],
    'imports' => [
        '@mpc/mpc-vidply/' => 'EXT:mpc_vidply/Resources/Public/JavaScript/',
    ],
];

