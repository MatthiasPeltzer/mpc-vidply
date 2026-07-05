<?php

defined('TYPO3') or die();

return [
    'dependencies' => ['backend'],
    'tags' => [
        'backend.form',
    ],
    'imports' => [
        '@mpc/mpc-vidply/' => 'EXT:mpc_vidply/Resources/Public/JavaScript/',
        '@mpc/mpc-vidply/Backend/media-url-import.js' => 'EXT:mpc_vidply/Resources/Public/JavaScript/Backend/media-url-import.js',
    ],
];
