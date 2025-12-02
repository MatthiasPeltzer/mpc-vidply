<?php

defined('TYPO3') or die();

/**
 * This extension requires Composer and cannot be installed via TER/Extension Manager.
 *
 * Installation:
 * composer require mpc/mpc-vidply
 *
 */

$_EXTKEY = "mpc_vidply";

$EM_CONF[$_EXTKEY] = [
    'title' => 'VidPly Player',
    'description' => 'Universal, Accessible Video & Audio Player for TYPO3. Includes support for HTML5 video/audio, YouTube, Vimeo, SoundCloud, HLS streaming, playlists, captions, transcripts, sign language, and full WCAG 2.1 AA accessibility compliance.',
    'category' => 'plugin',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'fluid' => '13.4.0-14.99.99',
            'extbase' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'state' => 'stable',
    'uploadfolder' => 0,
    'createDirs' => '',
    'clearCacheOnLoad' => 1,
    'author' => 'Matthias Peltzer',
    'author_email' => 'mail@mpeltzer.de',
    'version' => '1.0.9',
];
