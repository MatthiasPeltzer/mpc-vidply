<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Register custom FormEngine field wizard for media type filtering
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1700000000] = [
    'nodeName' => 'mpcVidplyMediaTypeFilterWizard',
    'priority' => 30,
    'class' => \Mpc\MpcVidply\Backend\Form\FieldWizard\MediaTypeFilterWizard::class,
];

