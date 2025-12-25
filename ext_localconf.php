<?php

declare(strict_types=1);

use Mpc\MpcVidply\OnlineMedia\Helpers\ExternalAudioHelper;
use Mpc\MpcVidply\OnlineMedia\Helpers\ExternalVideoHelper;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

defined('TYPO3') or die('Access denied.');

// Register custom Online Media helpers (Add media by URL)
// Only register if the corresponding allow-list is configured to avoid advertising providers that would always reject URLs.
try {
    $extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('mpc_vidply');
} catch (\Throwable) {
    $extConf = [];
}

if (trim((string)($extConf['allowedVideoDomains'] ?? '')) !== '') {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['onlineMediaHelpers']['externalvideo'] ??= ExternalVideoHelper::class;
    // Ensure Filelist treats the online media container file as a media file so thumbnails are rendered
    $mediaExt = GeneralUtility::trimExplode(',', (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['mediafile_ext'] ?? ''), true);
    if (!in_array('externalvideo', $mediaExt, true)) {
        $mediaExt[] = 'externalvideo';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['mediafile_ext'] = implode(',', $mediaExt);
    }
}

if (trim((string)($extConf['allowedAudioDomains'] ?? '')) !== '') {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['onlineMediaHelpers']['externalaudio'] ??= ExternalAudioHelper::class;
    // Ensure Filelist treats the online media container file as a media file so thumbnails are rendered
    $mediaExt = GeneralUtility::trimExplode(',', (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['mediafile_ext'] ?? ''), true);
    if (!in_array('externalaudio', $mediaExt, true)) {
        $mediaExt[] = 'externalaudio';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['mediafile_ext'] = implode(',', $mediaExt);
    }
}


