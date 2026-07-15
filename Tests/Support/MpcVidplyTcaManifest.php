<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Support;

use Mpc\MpcVidply\Backend\Preview\ListviewPreviewRenderer;
use Mpc\MpcVidply\Backend\Preview\VidPlyPreviewRenderer;

/**
 * Single source of truth for mpc-vidply TCA expectations used by configuration tests.
 */
final class MpcVidplyTcaManifest
{
    /**
     * @var list<string>
     */
    public const FUNCTIONAL_TEST_EXTENSIONS = ['mpc/mpc-vidply'];

    /**
     * @var list<string>
     */
    public const CUSTOM_TABLES = [
        'tx_mpcvidply_media',
        'tx_mpcvidply_listview_row',
        'tx_mpcvidply_privacy_settings',
    ];

    /**
     * @var list<string>
     */
    public const C_TYPES = [
        'mpc_vidply',
        'mpc_vidply_listview',
        'mpc_vidply_detail',
    ];

    /**
     * @var array<string, string>
     */
    public const C_TYPE_ICONS = [
        'mpc_vidply' => 'mpc_vidply-plugin',
        'mpc_vidply_listview' => 'mpc_vidply-listview',
        'mpc_vidply_detail' => 'mpc_vidply-detail',
    ];

    /**
     * @var array<string, list<string>>
     */
    public const OVERRIDDEN_CORE_TABLE_COLUMNS = [
        'tt_content' => ['tx_mpcvidply_media_items', 'tx_mpcvidply_listview_rows'],
        'sys_file_reference' => ['tx_lang_code', 'tx_track_kind', 'tx_desc_src_file'],
    ];

    /**
     * @var list<string>
     */
    public const SCHEMA_TABLES = [
        'tt_content',
        'tx_mpcvidply_media',
        'tx_mpcvidply_listview_row',
        'tx_mpcvidply_privacy_settings',
        'sys_file_reference',
    ];

    /**
     * @var array<string, class-string>
     */
    public const PREVIEW_RENDERERS = [
        'mpc_vidply' => VidPlyPreviewRenderer::class,
        'mpc_vidply_listview' => ListviewPreviewRenderer::class,
    ];
}
