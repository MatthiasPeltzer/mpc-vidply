<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media',
        'label' => 'title',
        'label_alt' => 'media_type',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'crdate',
        'type' => 'media_type',
        'typeicon_classes' => [
            'default' => 'mpc_vidply-plugin',
            'video' => 'mimetypes-media-video',
            'audio' => 'mimetypes-media-audio',
            'youtube' => 'mimetypes-media-video',
            'vimeo' => 'mimetypes-media-video',
            'soundcloud' => 'mimetypes-media-audio',
            'hls' => 'mimetypes-media-video',
            'm3u' => 'mimetypes-media-audio',
        ],
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ],
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'translationSource' => 'l10n_source',
        'iconfile' => 'EXT:mpc_vidply/Resources/Public/Icons/Extension.svg',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    media_type,
                    media_file,
                    media_url,
                    --palette--;;metadata,
                    poster,
                --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tabs.captions,
                    captions,
                    chapters,
                    enable_transcript,
                --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tabs.accessibility,
                    audio_description,
                    sign_language,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:categories,
                    categories,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
                    --palette--;;timeRestriction,
            ',
        ],
        '1' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    media_type,
                    media_file,
                    media_url,
                    --palette--;;metadata,
                    poster,
                --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tabs.captions,
                    captions,
                    chapters,
                    enable_transcript,
                --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tabs.accessibility,
                    audio_description,
                    sign_language,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:categories,
                    categories,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
                    --palette--;;timeRestriction,
            ',
        ],
        'video' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    media_type,
                    media_file,
                    --palette--;;metadata,
                    poster,
                --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tabs.captions,
                    captions,
                    chapters,
                    enable_transcript,
                --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tabs.accessibility,
                    audio_description,
                    sign_language,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:categories,
                    categories,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
                    --palette--;;timeRestriction,
            ',
            'columnsOverrides' => [
                'media_file' => [
                    'config' => [
                        'allowed' => 'webm,mp4,externalvideo',
                        'maxitems' => 10,
                    ],
                ],
            ],
        ],
        'audio' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    media_type,
                    media_file,
                    --palette--;;metadata,
                    poster,
                --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tabs.captions,
                    captions,
                    chapters,
                    enable_transcript,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:categories,
                    categories,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
                    --palette--;;timeRestriction,
            ',
            'columnsOverrides' => [
                'media_file' => [
                    'config' => [
                        'allowed' => 'mp3,ogg,externalaudio',
                        'maxitems' => 10,
                    ],
                ],
            ],
        ],
        'youtube' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    media_type,
                    media_file,
                    --palette--;;metadata,
                    poster,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:categories,
                    categories,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
                    --palette--;;timeRestriction,
            ',
            'columnsOverrides' => [
                'media_file' => [
                    'config' => [
                        'allowed' => 'youtube',
                        'maxitems' => 1,
                    ],
                ],
            ],
        ],
        'vimeo' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    media_type,
                    media_file,
                    --palette--;;metadata,
                    poster,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:categories,
                    categories,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
                    --palette--;;timeRestriction,
            ',
            'columnsOverrides' => [
                'media_file' => [
                    'config' => [
                        'allowed' => 'vimeo',
                        'maxitems' => 1,
                    ],
                ],
            ],
        ],
        'soundcloud' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    media_type,
                    media_file,
                    --palette--;;metadata,
                    poster,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:categories,
                    categories,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
                    --palette--;;timeRestriction,
            ',
            'columnsOverrides' => [
                'media_file' => [
                    'config' => [
                        'allowed' => 'soundcloud',
                        'maxitems' => 1,
                    ],
                ],
            ],
        ],
        'hls' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    media_type,
                    media_url,
                    --palette--;;metadata,
                    poster,
                --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tabs.captions,
                    captions,
                    chapters,
                    enable_transcript,
                --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tabs.accessibility,
                    audio_description,
                    sign_language,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:categories,
                    categories,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
                    --palette--;;timeRestriction,
            ',
        ],
        'm3u' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    media_type,
                    media_url,
                    --palette--;;metadata,
                    poster,
                --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tabs.captions,
                    captions,
                    chapters,
                    enable_transcript,
                --div--;LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tabs.accessibility,
                    audio_description,
                    sign_language,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:categories,
                    categories,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
                    --palette--;;timeRestriction,
            ',
        ],
    ],
    'palettes' => [
        'metadata' => [
            'showitem' => 'title,--linebreak--,artist,--linebreak--,description,--linebreak--,duration,audio_description_duration',
        ],
        'timeRestriction' => [
            'showitem' => 'starttime,endtime',
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
                'foreign_table' => 'tx_mpcvidply_media',
                'foreign_table_where' => 'AND tx_mpcvidply_media.pid=###CURRENT_PID### AND tx_mpcvidply_media.sys_language_uid IN (-1,0)',
                'default' => 0,
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
        'starttime' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.starttime',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
                'searchable' => false,
            ],
        ],
        'endtime' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.endtime',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
                'range' => [
                    'upper' => mktime(0, 0, 0, 1, 1, 2038),
                ],
                'searchable' => false,
            ],
        ],
        'media_type' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.media_type',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.media_type.video', 'value' => 'video'],
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.media_type.audio', 'value' => 'audio'],
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.media_type.youtube', 'value' => 'youtube'],
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.media_type.vimeo', 'value' => 'vimeo'],
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.media_type.soundcloud', 'value' => 'soundcloud'],
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.media_type.hls', 'value' => 'hls'],
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.media_type.m3u', 'value' => 'm3u'],
                ],
                'default' => 'video',
                'required' => true,
            ],
            'onChange' => 'reload',
        ],
        'media_url' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.media_url',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.media_url.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 1024,
                'eval' => 'trim',
                'required' => true,
                'placeholder' => 'https://example.com/stream.m3u8',
            ],
        ],
        'media_file' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.media_file',
            'config' => [
                'type' => 'file',
                'allowed' => 'webm,mp4,externalvideo,mp3,ogg,externalaudio,youtube,vimeo',
                'maxitems' => 10,
                'minitems' => 1,
                'overrideChildTca' => [
                    'columns' => [
                        'description' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                        'autoplay' => [
                            'config' => [
                                'renderType' => 'passthrough',
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'title' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.title',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'artist' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.artist',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'description' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.description',
            'config' => [
                'type' => 'text',
                'rows' => 5,
                'cols' => 50,
                'eval' => 'trim',
            ],
        ],
        'duration' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.duration',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.duration.description',
            'config' => [
                'type' => 'number',
                'format' => 'integer',
                'size' => 10,
                'default' => 0,
                'range' => [
                    'lower' => 0,
                ],
            ],
        ],
        'audio_description_duration' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.audio_description_duration',
            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.audio_description_duration.description',
            'config' => [
                'type' => 'number',
                'format' => 'integer',
                'size' => 10,
                'default' => 0,
                'range' => [
                    'lower' => 0,
                ],
            ],
        ],
        'poster' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.poster',
            'config' => [
                'type' => 'file',
                'allowed' => 'common-image-types',
                'maxitems' => 1,
                'overrideChildTca' => [
                    'columns' => [
                        'title' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                        'description' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                        'outline' => [
                            'config' => [
                                'renderType' => 'passthrough',
                                'type' => 'passthrough',
                            ],
                        ],
                        'allow_download' => [
                            'config' => [
                                'renderType' => 'passthrough',
                                'type' => 'passthrough',
                            ],
                        ],
                        'crop' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                        'link' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                    'types' => [
                        '1' => [
                            'showitem' => '
                                alternative,
                                --palette--;;filePalette,
                            ',
                        ],
                    ],
                ],
            ],
        ],
        'captions' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.captions',
            'config' => [
                'type' => 'file',
                'allowed' => 'vtt',
                'maxitems' => 20,
                'appearance' => [
                    'createNewRelationLinkTitle' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.captions.add',
                ],
                'overrideChildTca' => [
                    'columns' => [
                        'title' => [
                            'config' => [
                                'type' => 'input',
                                'size' => 30,
                                'max' => 255,
                                'placeholder' => 'Deutsch',
                                'eval' => 'trim',
                            ],
                        ],
                        'tx_lang_code' => [
                            'config' => [
                                'type' => 'input',
                                'size' => 10,
                                'max' => 10,
                                'placeholder' => 'de',
                                'eval' => 'trim',
                            ],
                        ],
                        'tx_track_kind' => [
                            'config' => [
                                'type' => 'select',
                                'renderType' => 'selectSingle',
                                'items' => [
                                    ['label' => 'Captions', 'value' => 'captions'],
                                    ['label' => 'Subtitles', 'value' => 'subtitles'],
                                    ['label' => 'Descriptions', 'value' => 'descriptions'],
                                    ['label' => 'Chapters', 'value' => 'chapters'],
                                    ['label' => 'Metadata', 'value' => 'metadata'],
                                ],
                                'default' => 'captions',
                            ],
                        ],
                        'tx_desc_src_file' => [
                            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_desc_src_file',
                            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_desc_src_file.description',
                            'config' => [
                                'type' => 'file',
                                'allowed' => 'vtt',
                                'maxitems' => 1,
                                'overrideChildTca' => [
                                    'columns' => [
                                        'title' => [
                                            'config' => [
                                                'type' => 'passthrough',
                                            ],
                                        ],
                                        'description' => [
                                            'config' => [
                                                'type' => 'passthrough',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'description' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                        'link' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                    'types' => [
                        '1' => [
                            'showitem' => '
                                tx_track_kind,title,tx_lang_code,
                                --palette--;;filePalette,
                                tx_desc_src_file,
                            ',
                        ],
                    ],
                ],
            ],
        ],
        'chapters' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.chapters',
            'config' => [
                'type' => 'file',
                'allowed' => 'vtt',
                'maxitems' => 5,
                'appearance' => [
                    'createNewRelationLinkTitle' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.chapters.add',
                ],
                'overrideChildTca' => [
                    'columns' => [
                        'title' => [
                            'config' => [
                                'type' => 'input',
                                'size' => 30,
                                'max' => 255,
                                'placeholder' => 'Deutsch',
                                'eval' => 'trim',
                            ],
                        ],
                        'tx_lang_code' => [
                            'config' => [
                                'type' => 'input',
                                'size' => 10,
                                'max' => 10,
                                'placeholder' => 'de',
                                'eval' => 'trim',
                            ],
                        ],
                        'tx_track_kind' => [
                            'config' => [
                                'type' => 'select',
                                'renderType' => 'selectSingle',
                                'items' => [
                                    ['label' => 'Captions', 'value' => 'captions'],
                                    ['label' => 'Subtitles', 'value' => 'subtitles'],
                                    ['label' => 'Descriptions', 'value' => 'descriptions'],
                                    ['label' => 'Chapters', 'value' => 'chapters'],
                                    ['label' => 'Metadata', 'value' => 'metadata'],
                                ],
                                'default' => 'chapters',
                            ],
                        ],
                        'tx_desc_src_file' => [
                            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_desc_src_file',
                            'description' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_desc_src_file.description',
                            'config' => [
                                'type' => 'file',
                                'allowed' => 'vtt',
                                'maxitems' => 1,
                                'overrideChildTca' => [
                                    'columns' => [
                                        'title' => [
                                            'config' => [
                                                'type' => 'passthrough',
                                            ],
                                        ],
                                        'description' => [
                                            'config' => [
                                                'type' => 'passthrough',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'description' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                        'link' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                    'types' => [
                        '1' => [
                            'showitem' => '
                                title,tx_lang_code,tx_track_kind,
                                --palette--;;filePalette,
                                tx_desc_src_file,
                            ',
                        ],
                    ],
                ],
            ],
        ],
        'audio_description' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.audio_description',
            'config' => [
                'type' => 'file',
                'allowed' => 'mp4,webm,externalvideo',
                'maxitems' => 5,
                'appearance' => [
                    'createNewRelationLinkTitle' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.audio_description.add',
                ],
                'overrideChildTca' => [
                    'columns' => [
                        'description' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                        'autoplay' => [
                            'config' => [
                                'renderType' => 'passthrough',
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'sign_language' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.sign_language',
            'config' => [
                'type' => 'file',
                'allowed' => 'mp4,webm,externalvideo',
                'maxitems' => 5,
                'appearance' => [
                    'createNewRelationLinkTitle' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.sign_language.add',
                ],
                'overrideChildTca' => [
                    'columns' => [
                        'description' => [
                            'config' => [
                                'type' => 'passthrough',
                            ],
                        ],
                        'autoplay' => [
                            'config' => [
                                'renderType' => 'passthrough',
                                'type' => 'passthrough',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'enable_transcript' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:tx_mpcvidply_media.enable_transcript',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'items' => [
                    [
                        'label' => '',
                    ],
                ],
            ],
        ],
        'categories' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.categories',
            'config' => [
                'type' => 'category',
            ],
        ],
    ],
];
