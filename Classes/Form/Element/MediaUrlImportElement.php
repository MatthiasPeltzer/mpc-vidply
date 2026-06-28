<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Form\Element;

use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

final class MediaUrlImportElement extends AbstractFormElement
{
    public function render(): array
    {
        $resultArray = $this->initializeResultArray();
        $languageService = $this->getLanguageService();

        $databaseRow = $this->data['databaseRow'] ?? [];
        $recordUid = $this->resolveScalarFormValue($databaseRow['uid'] ?? null);
        $pid = (int)($this->data['effectivePid'] ?? $this->resolveScalarFormValue($databaseRow['pid'] ?? null, '0'));
        $currentMediaType = (string)($this->data['recordTypeValue'] ?? '');
        if ($currentMediaType === '') {
            $currentMediaType = $this->resolveScalarFormValue($databaseRow['media_type'] ?? null, 'video');
        }
        $linkedFileUid = $this->resolveLinkedMediaFileUid();

        $pendingImport = $this->data['customData']['mpcVidplyPendingImport'] ?? null;
        $typeMismatchWarning = (string)($this->data['customData']['mpcVidplyTypeMismatchWarning'] ?? '');
        $importLabel = (string)($this->data['customData']['mpcVidplyImportLabel'] ?? '');

        $fieldId = 'vidply-media-url-import-' . md5($recordUid . '-' . $this->data['fieldName']);
        $itemFormElName = (string)($this->data['parameterArray']['itemFormElName'] ?? '');

        $resultArray['javaScriptModules'][] = JavaScriptModuleInstruction::create(
            '@mpc/mpc-vidply/Backend/media-url-import.js'
        );

        $pendingJson = '';
        if (is_array($pendingImport) && $pendingImport !== []) {
            $pendingJson = htmlspecialchars(
                (string)json_encode($pendingImport, JSON_THROW_ON_ERROR),
                ENT_QUOTES | ENT_HTML5
            );
        }

        $statusHtml = '';
        if ($importLabel !== '') {
            $statusHtml .= '<div class="alert alert-success mb-2" role="status">'
                . htmlspecialchars(sprintf(
                    $languageService->sL('LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:mediaUrlImport.importSuccess'),
                    $importLabel
                ))
                . '</div>';
        }
        if ($typeMismatchWarning !== '') {
            $statusHtml .= '<div class="alert alert-warning mb-2" role="alert">'
                . htmlspecialchars($typeMismatchWarning)
                . '</div>';
        }

        $refreshButton = '';
        if ($linkedFileUid > 0) {
            $refreshLabel = htmlspecialchars(
                $languageService->sL('LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:mediaUrlImport.refreshMetadata')
            );
            $refreshButton = '<button type="button" class="btn btn-default t3js-vidply-media-url-refresh" '
                . 'data-file-uid="' . $linkedFileUid . '">'
                . $refreshLabel
                . '</button>';
        }

        $importLabelText = htmlspecialchars(
            $languageService->sL('LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:mediaUrlImport.import')
        );
        $placeholder = htmlspecialchars(
            $languageService->sL('LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:mediaUrlImport.placeholder')
        );
        $description = htmlspecialchars(
            $languageService->sL('LLL:EXT:mpc_vidply/Resources/Private/Language/locallang_be.xlf:mediaUrlImport.description')
        );

        $resultArray['html'] = '
            <div class="form-control-wrap vidply-media-url-import-wrap"'
            . ($pendingJson !== '' ? ' data-vidply-pending-import="' . $pendingJson . '"' : '')
            . ' id="' . htmlspecialchars($fieldId) . '"
                 data-record-identifier="' . htmlspecialchars($recordUid) . '"
                 data-item-name="' . htmlspecialchars($itemFormElName) . '"
                 data-table-name="tx_mpcvidply_media"
                 data-pid="' . $pid . '"
                 data-current-media-type="' . htmlspecialchars($currentMediaType) . '">
                ' . $statusHtml . '
                <p class="form-text text-muted mb-2">' . $description . '</p>
                <div class="input-group">
                    <input type="url"
                           class="form-control t3js-vidply-media-url-input"
                           placeholder="' . $placeholder . '"
                           autocomplete="off"
                           spellcheck="false">
                    <button type="button" class="btn btn-primary t3js-vidply-media-url-import">'
                        . $importLabelText .
                    '</button>
                    ' . $refreshButton . '
                </div>
                <div class="form-text mt-1 t3js-vidply-media-url-status" aria-live="polite"></div>
            </div>
        ';

        return $resultArray;
    }

    private function resolveLinkedMediaFileUid(): int
    {
        $children = $this->data['processedTca']['columns']['media_file']['children'] ?? [];
        if ($children === []) {
            return 0;
        }

        $databaseRow = $children[0]['databaseRow'] ?? null;
        if (!is_array($databaseRow)) {
            return 0;
        }

        $uidLocal = $databaseRow['uid_local'] ?? null;
        if (is_array($uidLocal)) {
            if (isset($uidLocal[0]['uid'])) {
                return (int)$uidLocal[0]['uid'];
            }
            return (int)($uidLocal[0] ?? 0);
        }

        return (int)$uidLocal;
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
