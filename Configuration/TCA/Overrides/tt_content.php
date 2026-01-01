<?php

declare(strict_types=1);

use Mpc\MpcVidply\Backend\Preview\VidPlyPreviewRenderer;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Register the content element
$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['mpc_vidply'] = 'mpc_vidply-plugin';

// Add the CType to the select list
ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:content_element.title',
        'value' => 'mpc_vidply',
        'icon' => 'mpc_vidply-plugin',
        'group' => 'default',
        'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:content_element.description',
    ]
);

// Add VidPly-specific fields
$vidplyFields = [
    // Media Items
    'tx_mpcvidply_media_items' => [
        'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_media_items',
        'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_media_items.description',
        'config' => [
            'type' => 'group',
            'allowed' => 'tx_mpcvidply_media',
            'MM' => 'tx_mpcvidply_content_media_mm',
            'size' => 5,
            'minitems' => 1,
            'maxitems' => 999,
            'autoSizeMax' => 10,
            'behaviour' => [
                'allowLanguageSynchronization' => true,
            ],
            'fieldControl' => [
                'editPopup' => ['disabled' => false],
                'addRecord' => [
                    'disabled' => false,
                    'options' => [
                        'title' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_media_items.add',
                        'setValue' => 'prepend',
                    ],
                ],
                'listModule' => ['disabled' => false],
            ],
            'suggestOptions' => [
                'default' => [
                    'searchWholePhrase' => true,
                    'addWhere' => ' AND tx_mpcvidply_media.sys_language_uid IN (-1, 0)',
                ],
            ],
        ],
    ],
    
    // Player Options
    'tx_mpcvidply_options' => [
        'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_options',
        'config' => [
            'type' => 'check',
            'items' => [
                ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_options.autoplay', 'value' => 1],
                ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_options.loop', 'value' => 2],
                ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_options.muted', 'value' => 4],
                ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_options.controls', 'value' => 8],
                ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_options.captions_default', 'value' => 16],
                ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_options.keyboard', 'value' => 64],
                ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_options.auto_advance', 'value' => 256],
            ],
            // Default: controls + keyboard + auto-advance (8 + 64 + 256).
            // Responsive is always enabled (no toggle), and transcript is per-media item only.
            'default' => 328,
        ],
    ],
    
    // Playback Settings
    'tx_mpcvidply_volume' => [
        'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_volume',
        'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_volume.description',
        'config' => [
            'type' => 'number',
            'format' => 'decimal',
            'default' => 0.8,
            'range' => ['lower' => 0, 'upper' => 1],
        ],
    ],
    'tx_mpcvidply_playback_speed' => [
        'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_playback_speed',
        'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_playback_speed.description',
        'config' => [
            'type' => 'number',
            'format' => 'decimal',
            'default' => 1.0,
            'range' => ['lower' => 0.25, 'upper' => 2.0],
        ],
    ],
    
    // Localization
    'tx_mpcvidply_language' => [
        'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_language',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_language.auto', 'value' => ''],
                ['label' => 'English', 'value' => 'en'],
                ['label' => 'Español', 'value' => 'es'],
                ['label' => 'Français', 'value' => 'fr'],
                ['label' => 'Deutsch', 'value' => 'de'],
                ['label' => '日本語', 'value' => 'ja'],
            ],
            'default' => '',
        ],
    ],
];

// Add fields to TCA
ExtensionManagementUtility::addTCAcolumns('tt_content', $vidplyFields);

// Define the showitem configuration for the VidPly content element
$GLOBALS['TCA']['tt_content']['types']['mpc_vidply'] = [
    'showitem' => '
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
            --palette--;;general,
            --palette--;;header,
            subheader,
        --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tabs.media,
            tx_mpcvidply_media_items,
        --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tabs.settings,
            tx_mpcvidply_options,
            tx_mpcvidply_volume,
            tx_mpcvidply_playback_speed,
            tx_mpcvidply_language,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:appearance,
            --palette--;;appearance,
            --palette--;;frames,
            --palette--;;appearanceLinks,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:categories,
            categories,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:language,
            --palette--;;language,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
            --palette--;;hidden,
            --palette--;;access,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:notes,
            rowDescription,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:extended,
    ',
    'previewRenderer' => VidPlyPreviewRenderer::class,
];


