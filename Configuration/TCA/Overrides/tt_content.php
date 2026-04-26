<?php

declare(strict_types=1);

use Mpc\MpcVidply\Backend\Preview\ListviewPreviewRenderer;
use Mpc\MpcVidply\Backend\Preview\VidPlyPreviewRenderer;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Register the content element
$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['mpc_vidply'] = 'mpc_vidply-plugin';
$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['mpc_vidply_listview'] = 'mpc_vidply-listview';
$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['mpc_vidply_detail'] = 'mpc_vidply-detail';

// Add the CType to the select list
ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:content_element.title',
        'value' => 'mpc_vidply',
        'icon' => 'mpc_vidply-plugin',
        'group' => 'plugins',
        'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:content_element.description',
    ]
);

ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:content_element.listview.title',
        'value' => 'mpc_vidply_listview',
        'icon' => 'mpc_vidply-listview',
        'group' => 'plugins',
        'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:content_element.listview.description',
    ]
);

ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:content_element.detail.title',
        'value' => 'mpc_vidply_detail',
        'icon' => 'mpc_vidply-detail',
        'group' => 'plugins',
        'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:content_element.detail.description',
    ]
);

// Add VidPly-specific fields
$vidplyFields = [
    // Media Items
    'tx_mpcvidply_media_items' => [
        'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_media_items',
        'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_media_items.description',
        'l10n_mode' => 'exclude',
        'config' => [
            'type' => 'group',
            'allowed' => 'tx_mpcvidply_media',
            'MM' => 'tx_mpcvidply_content_media_mm',
            'size' => 5,
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

    // Detail content element: "You might also like" row (same category)
    'tx_mpcvidply_show_related' => [
        'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_show_related',
        'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_show_related.description',
        'displayCond' => 'FIELD:CType:=:mpc_vidply_detail',
        'l10n_mode' => 'exclude',
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'items' => [
                [
                    'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_show_related.toggle',
                    'value' => 1,
                ],
            ],
            'default' => 1,
        ],
    ],

    // Note: Play button icon and position are configured site-wide via Extension Configuration only
    // (Admin Tools → Settings → Extension Configuration → mpc_vidply)
];

// Additional fields used by the Listview / Detail content elements
$vidplyFields['tx_mpcvidply_listview_rows'] = [
    'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_listview_rows',
    'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_listview_rows.description',
    'config' => [
        'type' => 'inline',
        'foreign_table' => 'tx_mpcvidply_listview_row',
        'foreign_field' => 'parentid',
        'foreign_table_field' => 'parenttable',
        'foreign_match_fields' => [
            'parentfield' => 'tx_mpcvidply_listview_rows',
        ],
        'maxitems' => 50,
        'appearance' => [
            'collapseAll' => true,
            'expandSingle' => true,
            'levelLinksPosition' => 'both',
            'showSynchronizationLink' => true,
            'showAllLocalizationLink' => true,
            'showPossibleLocalizationRecords' => true,
            'useSortable' => true,
        ],
        'behaviour' => [
            'allowLanguageSynchronization' => true,
        ],
    ],
];

$vidplyFields['tx_mpcvidply_detail_page'] = [
    'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_detail_page',
    'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tt_content.tx_mpcvidply_detail_page.description',
    'l10n_mode' => 'exclude',
    'config' => [
        'type' => 'group',
        'allowed' => 'pages',
        'size' => 1,
        'maxitems' => 1,
        'minitems' => 0,
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

// Listview content element (Mediathek style — multiple "shelves" of media cards)
$GLOBALS['TCA']['tt_content']['types']['mpc_vidply_listview'] = [
    'showitem' => '
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
            --palette--;;general,
            --palette--;;header,
            subheader,
        --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tabs.listview_rows,
            tx_mpcvidply_listview_rows,
        --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tabs.listview_settings,
            tx_mpcvidply_detail_page,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:appearance,
            --palette--;;appearance,
            --palette--;;frames,
            --palette--;;appearanceLinks,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:language,
            --palette--;;language,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
            --palette--;;hidden,
            --palette--;;access,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:notes,
            rowDescription,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:extended,
    ',
    'previewRenderer' => ListviewPreviewRenderer::class,
];

// Detail content element — placed on a detail page; resolves media by ?media=<uid|slug>
$GLOBALS['TCA']['tt_content']['types']['mpc_vidply_detail'] = [
    'showitem' => '
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
            --palette--;;general,
            --palette--;;header,
            subheader,
        --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tabs.settings,
            tx_mpcvidply_options,
            tx_mpcvidply_volume,
            tx_mpcvidply_playback_speed,
            tx_mpcvidply_language,
            tx_mpcvidply_show_related,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:appearance,
            --palette--;;appearance,
            --palette--;;frames,
            --palette--;;appearanceLinks,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:language,
            --palette--;;language,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
            --palette--;;hidden,
            --palette--;;access,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:notes,
            rowDescription,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:extended,
    ',
];


