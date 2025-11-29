<?php

defined('TYPO3') or die();

$_EXTKEY = 'mpc_vidply';

$EM_CONF['mpc_vidply'] = array(
    'title' => 'VidPly Player',
    'description' => 'Universal, Accessible Video & Audio Player for TYPO3. Includes support for HTML5 video/audio, YouTube, Vimeo, SoundCloud, HLS streaming, playlists, captions, transcripts, sign language, and full WCAG 2.1 AA accessibility compliance.',
    'category' => 'plugin',
    'state' => 'stable',
    'author' => 'Matthias Peltzer',
    'author_email' => 'mail@mpeltzer.de',
    'version' => '1.0.0',
    'constraints' => array(
        'depends' => array(
            'typo3' => '13.4.0-14.99.99',
            'fluid' => '13.4.0-14.99.99',
            'extbase' => '13.4.0-14.99.99',
        ),
        'conflicts' => array(),
        'suggests' => array(),
    ),
);

