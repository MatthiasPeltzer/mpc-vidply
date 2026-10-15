<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Tests\Functional\Configuration;

use Mpc\MpcVidply\Tests\Support\MpcVidplyTcaManifest;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\Localization\State;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Mpc\MpcVidply\Tests\Functional\MpcVidplyFunctionalTestCase;

final class TcaBootstrapFunctionalTest extends MpcVidplyFunctionalTestCase
{
    protected array $testExtensionsToLoad = MpcVidplyTcaManifest::FUNCTIONAL_TEST_EXTENSIONS;

    #[Test]
    public function customTablesAreRegisteredInTca(): void
    {
        foreach (MpcVidplyTcaManifest::CUSTOM_TABLES as $table) {
            self::assertArrayHasKey($table, $GLOBALS['TCA']);
            self::assertArrayHasKey('ctrl', $GLOBALS['TCA'][$table]);
            self::assertArrayHasKey('columns', $GLOBALS['TCA'][$table]);
            self::assertArrayHasKey('types', $GLOBALS['TCA'][$table]);
        }
    }

    #[Test]
    public function contentTypesAreRegisteredInTtContent(): void
    {
        foreach (MpcVidplyTcaManifest::C_TYPES as $cType) {
            self::assertArrayHasKey($cType, $GLOBALS['TCA']['tt_content']['types']);
        }
    }

    #[Test]
    public function contentTypeIconsAreRegistered(): void
    {
        $typeIcons = $GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes'] ?? [];
        self::assertIsArray($typeIcons);

        foreach (MpcVidplyTcaManifest::C_TYPE_ICONS as $cType => $icon) {
            self::assertSame($icon, $typeIcons[$cType] ?? null);
        }
    }

    #[Test]
    public function tcaSchemaBuildsForMpcVidplyTables(): void
    {
        $factory = GeneralUtility::makeInstance(TcaSchemaFactory::class);

        foreach (MpcVidplyTcaManifest::SCHEMA_TABLES as $table) {
            self::assertTrue($factory->has($table));
            self::assertSame($table, $factory->get($table)->getName());
        }
    }

    #[Test]
    public function coreTableOverridesExposeMpcVidplyColumns(): void
    {
        foreach (MpcVidplyTcaManifest::OVERRIDDEN_CORE_TABLE_COLUMNS as $table => $columns) {
            self::assertArrayHasKey($table, $GLOBALS['TCA']);

            foreach ($columns as $column) {
                self::assertArrayHasKey($column, $GLOBALS['TCA'][$table]['columns']);
            }
        }
    }

    #[Test]
    public function behaviourIsOnlyConfiguredInsideColumnConfig(): void
    {
        $tables = array_merge(
            MpcVidplyTcaManifest::CUSTOM_TABLES,
            array_keys(MpcVidplyTcaManifest::OVERRIDDEN_CORE_TABLE_COLUMNS),
        );

        foreach (array_unique($tables) as $table) {
            foreach ($GLOBALS['TCA'][$table]['columns'] as $column => $columnConfig) {
                self::assertArrayNotHasKey(
                    'behaviour',
                    $columnConfig,
                    sprintf('%s.%s: "behaviour" must sit inside "config", core ignores it on column level.', $table, $column),
                );
            }
        }
    }

    #[Test]
    public function translatedFieldsCanBeSynchronizedWithTheirParent(): void
    {
        $expected = [
            'tt_content' => [
                'tx_mpcvidply_layout',
                'tx_mpcvidply_playlist_position',
                'tx_mpcvidply_episode_sort',
                'tx_mpcvidply_episode_pagination',
                'tx_mpcvidply_episode_per_page',
                'tx_mpcvidply_resume_playback',
                'tx_mpcvidply_show_track_info',
            ],
            'tx_mpcvidply_media' => ['publish_date', 'episode_number'],
        ];

        foreach ($expected as $table => $columns) {
            $synchronizable = State::getFieldNames($table);
            foreach ($columns as $column) {
                self::assertContains($column, $synchronizable, $table . '.' . $column);
            }
        }
    }

    #[Test]
    public function previewRenderersAreRegisteredWhereExpected(): void
    {
        foreach (MpcVidplyTcaManifest::PREVIEW_RENDERERS as $cType => $rendererClass) {
            self::assertSame(
                $rendererClass,
                $GLOBALS['TCA']['tt_content']['types'][$cType]['previewRenderer'] ?? null,
            );
        }
    }
}
