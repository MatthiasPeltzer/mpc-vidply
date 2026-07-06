<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Form\FormDataProvider;

use Mpc\MpcVidply\Service\MediaUrlImportSessionService;
use TYPO3\CMS\Backend\Form\FormDataProviderInterface;

/**
 * Applies pending URL-import values after FormEngine reload.
 */
final class MediaUrlImportFormDataProvider implements FormDataProviderInterface
{
    public function __construct(
        private readonly MediaUrlImportSessionService $sessionService,
    ) {}

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function addData(array $result): array
    {
        if (($result['tableName'] ?? '') !== 'tx_mpcvidply_media') {
            return $result;
        }

        $uid = $this->resolveScalarFormValue($result['databaseRow']['uid'] ?? null);
        if ($uid === '') {
            return $result;
        }

        $recordKey = $this->sessionService->buildRecordKey('tx_mpcvidply_media', $uid);
        $pending = $this->sessionService->pull($recordKey);
        if ($pending === null) {
            return $result;
        }

        if (!empty($pending['mediaType'])) {
            $result['databaseRow']['media_type'] = (string)$pending['mediaType'];
        }

        if (!empty($pending['title']) && trim((string)($result['databaseRow']['title'] ?? '')) === '') {
            $result['databaseRow']['title'] = (string)$pending['title'];
        }

        if (!empty($pending['artist']) && trim((string)($result['databaseRow']['artist'] ?? '')) === '') {
            $result['databaseRow']['artist'] = (string)$pending['artist'];
        }

        if (!empty($pending['description']) && trim((string)($result['databaseRow']['description'] ?? '')) === '') {
            $result['databaseRow']['description'] = (string)$pending['description'];
        }

        if (!empty($pending['duration']) && (int)($result['databaseRow']['duration'] ?? 0) === 0) {
            $result['databaseRow']['duration'] = (int)$pending['duration'];
        }

        $pendingImport = [];
        foreach (['mediaType', 'mediaFileUid', 'posterFileUid', 'title', 'artist', 'description', 'duration'] as $key) {
            if (!empty($pending[$key])) {
                $pendingImport[$key] = $pending[$key];
            }
        }
        if ($pendingImport !== []) {
            $result['customData']['mpcVidplyPendingImport'] = $pendingImport;
        }

        if (!empty($pending['typeMismatchWarning'])) {
            $result['customData']['mpcVidplyTypeMismatchWarning'] = (string)$pending['typeMismatchWarning'];
        }

        if (!empty($pending['detectedLabel'])) {
            $result['customData']['mpcVidplyImportLabel'] = (string)$pending['detectedLabel'];
        }

        return $result;
    }

    private function resolveScalarFormValue(mixed $value, string $default = ''): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return $default;
            }

            $first = reset($value);
            if (is_array($first)) {
                if (isset($first['value'])) {
                    return (string)$first['value'];
                }
                if (isset($first['uid'])) {
                    return (string)$first['uid'];
                }
            }

            return (string)$first;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return (string)$value;
    }
}
