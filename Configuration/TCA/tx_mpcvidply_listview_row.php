<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row',
        'label' => 'headline',
        'label_alt' => 'selection_mode',
        'label_alt_force' => false,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'sorting',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'translationSource' => 'l10n_source',
        'iconfile' => 'EXT:mpc_vidply/Resources/Public/Icons/Extension.svg',
        'hideTable' => true,
        'security' => [
            // Child records of tt_content — do not enforce page doktype.
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    headline,
                    headline_link,
                    --palette--;;presentation,
                --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.tabs.selection,
                    selection_mode,
                    media_items,
                    categories,
                    --palette--;;filters,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:language,
                    --palette--;;language,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
            ',
        ],
    ],
    'palettes' => [
        'presentation' => [
            'showitem' => 'layout,--linebreak--,card_style,limit_items,--linebreak--,enable_pagination,pagination_per_page',
        ],
        'filters' => [
            'showitem' => 'sort_by,--linebreak--',
        ],
        'language' => [
            'showitem' => 'sys_language_uid,l10n_parent',
        ],
    ],
    'columns' => [
        'sys_language_uid' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.language',
            'config' => [
                'type' => 'language',
            ],
        ],
        'l10n_parent' => [
            'displayCond' => 'FIELD:sys_language_uid:>:0',
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.l18n_parent',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => '', 'value' => 0],
                ],
                'foreign_table' => 'tx_mpcvidply_listview_row',
                'foreign_table_where' => 'AND {#tx_mpcvidply_listview_row}.{#pid}=###CURRENT_PID### AND {#tx_mpcvidply_listview_row}.{#sys_language_uid} IN (-1,0)',
                'default' => 0,
            ],
        ],
        'l10n_diffsource' => ['config' => ['type' => 'passthrough']],
        'l10n_source' => ['config' => ['type' => 'passthrough']],
        'l10n_state' => ['config' => ['type' => 'passthrough']],
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'items' => [
                    [
                        'label' => '',
                        'invertStateDisplay' => true,
                    ],
                ],
            ],
        ],
        'parentid' => ['config' => ['type' => 'passthrough']],
        'parenttable' => ['config' => ['type' => 'passthrough']],
        'parentfield' => ['config' => ['type' => 'passthrough']],
        'headline' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.headline',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'headline_link' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.headline_link',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.headline_link.description',
            'config' => [
                'type' => 'link',
                'size' => 50,
                'appearance' => [
                    'allowedTypes' => ['page', 'url', 'record'],
                ],
            ],
        ],
        'layout' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.layout',
            'onChange' => 'reload',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.layout.shelf', 'value' => 'shelf'],
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.layout.grid', 'value' => 'grid'],
                ],
                'default' => 'shelf',
            ],
        ],
        'card_style' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.card_style',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.card_style.poster', 'value' => 'poster'],
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.card_style.poster_compact', 'value' => 'poster_compact'],
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.card_style.landscape', 'value' => 'landscape'],
                ],
                'default' => 'poster',
            ],
        ],
        'limit_items' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.limit_items',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.limit_items.description',
            'config' => [
                'type' => 'number',
                'format' => 'integer',
                'size' => 6,
                'default' => 12,
                'range' => ['lower' => 1, 'upper' => 200],
            ],
        ],
        'enable_pagination' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.enable_pagination',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.enable_pagination.description',
            'displayCond' => 'FIELD:layout:=:grid',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 1,
                'items' => [
                    [
                        'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.enable_pagination.toggle',
                        'invertStateDisplay' => false,
                    ],
                ],
            ],
        ],
        'pagination_per_page' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.pagination_per_page',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.pagination_per_page.description',
            'displayCond' => [
                'AND' => [
                    'FIELD:layout:=:grid',
                    'FIELD:enable_pagination:=:1',
                ],
            ],
            'config' => [
                'type' => 'number',
                'format' => 'integer',
                'size' => 6,
                'default' => 12,
                'range' => ['lower' => 1, 'upper' => 200],
            ],
        ],
        'sort_by' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.sort_by',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.sort_by.sorting', 'value' => 'sorting'],
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.sort_by.crdate_desc', 'value' => 'crdate_desc'],
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.sort_by.title_asc', 'value' => 'title_asc'],
                ],
                'default' => 'sorting',
            ],
        ],
        'selection_mode' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.selection_mode',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.selection_mode.manual', 'value' => 'manual'],
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.selection_mode.category', 'value' => 'category'],
                ],
                'default' => 'manual',
            ],
            'onChange' => 'reload',
        ],
        'media_items' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.media_items',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.media_items.description',
            'displayCond' => 'FIELD:selection_mode:=:manual',
            'config' => [
                'type' => 'group',
                'allowed' => 'tx_mpcvidply_media',
                'MM' => 'tx_mpcvidply_listview_row_media_mm',
                'size' => 5,
                'maxitems' => 999,
                'autoSizeMax' => 10,
                'fieldControl' => [
                    'editPopup' => ['disabled' => false],
                    'addRecord' => [
                        'disabled' => false,
                        'options' => [
                            'title' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.media_items.add',
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
        'categories' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.categories',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_listview_row.categories.description',
            'displayCond' => 'FIELD:selection_mode:=:category',
            'config' => [
                'type' => 'category',
                'size' => 5,
                'maxitems' => 25,
            ],
        ],
    ],
];
