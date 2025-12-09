<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

(static function (): void {
    $newColumns = [
        'tx_lang_code' => [
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_lang_code',
            'config' => [
                'type' => 'input',
                'size' => 10,
                'max' => 10,
                'eval' => 'trim',
                'placeholder' => 'de',
                'behaviour' => [
                    'allowLanguageSynchronization' => true,
                ],
            ],
        ],
        'tx_track_kind' => [
            'label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_track_kind',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_track_kind.captions', 'value' => 'captions'],
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_track_kind.subtitles', 'value' => 'subtitles'],
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_track_kind.descriptions', 'value' => 'descriptions'],
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_track_kind.chapters', 'value' => 'chapters'],
                    ['label' => 'LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_track_kind.metadata', 'value' => 'metadata'],
                ],
                'default' => 'captions',
                'behaviour' => [
                    'allowLanguageSynchronization' => true,
                ],
            ],
        ],
        'tx_quality_label' => [
            'label' => 'LLL:EXT:mp_core/Resources/Private/Language/locallang_db.xlf:sys_file_reference.tx_quality_label',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'max' => 50,
                'eval' => 'trim',
                'placeholder' => '1080p, 720p, High, Low',
                'behaviour' => [
                    'allowLanguageSynchronization' => true,
                ],
            ],
        ],
    ];

    ExtensionManagementUtility::addTCAcolumns('sys_file_reference', $newColumns);
})();
