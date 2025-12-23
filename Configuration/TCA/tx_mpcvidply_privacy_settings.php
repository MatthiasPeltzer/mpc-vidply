<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:tx_mpcvidply_privacy_settings',
        'label' => 'uid',
        'label_userFunc' => \Mpc\MpcVidply\Tca\PrivacySettingsLabel::class . '->getLabel',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'rootLevel' => -1, // Allow on root level AND in page tree
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'iconfile' => 'EXT:mpc_vidply/Resources/Public/Icons/Extension.svg',
        'searchFields' => 'uid',
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.tabs.youtube,
                    youtube_headline,
                    youtube_intro_text,
                    youtube_outro_text,
                    youtube_policy_link,
                    youtube_link_text,
                    youtube_button_label,
                --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.tabs.vimeo,
                    vimeo_headline,
                    vimeo_intro_text,
                    vimeo_outro_text,
                    vimeo_policy_link,
                    vimeo_link_text,
                    vimeo_button_label,
                --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.tabs.soundcloud,
                    soundcloud_headline,
                    soundcloud_intro_text,
                    soundcloud_outro_text,
                    soundcloud_policy_link,
                    soundcloud_link_text,
                    soundcloud_button_label,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
            ',
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
                'default' => 0,
                'items' => [
                    [
                        'label' => '',
                        'value' => 0,
                    ],
                ],
                'foreign_table' => 'tx_mpcvidply_privacy_settings',
                'foreign_table_where' => 'AND {#tx_mpcvidply_privacy_settings}.{#pid}=###CURRENT_PID### AND {#tx_mpcvidply_privacy_settings}.{#sys_language_uid} IN (-1,0)',
            ],
        ],
        'l10n_diffsource' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
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
        
        // YouTube Settings
        'youtube_headline' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.youtube_headline',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.youtube_headline.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'youtube_intro_text' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.youtube_intro_text',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.youtube_intro_text.description',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'cols' => 50,
                'eval' => 'trim',
                'placeholder' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang.xlf:privacy.activate_intro',
            ],
        ],
        'youtube_outro_text' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.youtube_outro_text',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.youtube_outro_text.description',
            'config' => [
                'type' => 'text',
                'rows' => 2,
                'cols' => 50,
                'eval' => 'trim',
                'placeholder' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang.xlf:privacy.activate_outro',
            ],
        ],
        'youtube_policy_link' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.youtube_policy_link',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.youtube_policy_link.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
                'placeholder' => 'https://policies.google.com/privacy',
            ],
        ],
        'youtube_link_text' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.youtube_link_text',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.youtube_link_text.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'youtube_button_label' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.youtube_button_label',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.youtube_button_label.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        
        // Vimeo Settings
        'vimeo_headline' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.vimeo_headline',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.vimeo_headline.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'vimeo_intro_text' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.vimeo_intro_text',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.vimeo_intro_text.description',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'cols' => 50,
                'eval' => 'trim',
                'placeholder' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang.xlf:privacy.activate_intro',
            ],
        ],
        'vimeo_outro_text' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.vimeo_outro_text',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.vimeo_outro_text.description',
            'config' => [
                'type' => 'text',
                'rows' => 2,
                'cols' => 50,
                'eval' => 'trim',
                'placeholder' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang.xlf:privacy.activate_outro',
            ],
        ],
        'vimeo_policy_link' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.vimeo_policy_link',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.vimeo_policy_link.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
                'placeholder' => 'https://vimeo.com/privacy',
            ],
        ],
        'vimeo_link_text' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.vimeo_link_text',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.vimeo_link_text.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'vimeo_button_label' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.vimeo_button_label',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.vimeo_button_label.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        
        // SoundCloud Settings
        'soundcloud_headline' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.soundcloud_headline',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.soundcloud_headline.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'soundcloud_intro_text' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.soundcloud_intro_text',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.soundcloud_intro_text.description',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'cols' => 50,
                'eval' => 'trim',
                'placeholder' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang.xlf:privacy.activate_intro_widget',
            ],
        ],
        'soundcloud_outro_text' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.soundcloud_outro_text',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.soundcloud_outro_text.description',
            'config' => [
                'type' => 'text',
                'rows' => 2,
                'cols' => 50,
                'eval' => 'trim',
                'placeholder' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang.xlf:privacy.activate_outro',
            ],
        ],
        'soundcloud_policy_link' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.soundcloud_policy_link',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.soundcloud_policy_link.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
                'placeholder' => 'https://soundcloud.com/pages/privacy',
            ],
        ],
        'soundcloud_link_text' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.soundcloud_link_text',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.soundcloud_link_text.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'soundcloud_button_label' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.soundcloud_button_label',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_privacy_settings.soundcloud_button_label.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
    ],
];

