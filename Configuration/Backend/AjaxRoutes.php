<?php

declare(strict_types=1);

use Mpc\MpcVidply\Backend\Controller\MediaImportAjaxController;

return [
    'mpc_vidply_media_import_url' => [
        'path' => '/mpc-vidply/media/import-url',
        'methods' => ['POST'],
        'target' => MediaImportAjaxController::class . '::importAction',
    ],
    'mpc_vidply_media_refresh_metadata' => [
        'path' => '/mpc-vidply/media/refresh-metadata',
        'methods' => ['POST'],
        'target' => MediaImportAjaxController::class . '::refreshAction',
    ],
];
