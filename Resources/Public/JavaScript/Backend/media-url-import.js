/*
 * VidPly media record: paste URL → auto-detect type and import metadata.
 */
import DocumentService from '@typo3/core/document-service.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import FormEngine from '@typo3/backend/form-engine.js';
import { MessageUtility } from '@typo3/backend/utility/message-utility.js';
import RegularEvent from '@typo3/core/event/regular-event.js';

const IMPORT_FIELD = '[import_source_url]';

const FILE_CONTAINER_SELECTORS = [
  'typo3-formengine-container-inline',
  'typo3-formengine-container-files',
].join(',');

const SELECTORS = {
  wrap: '.vidply-media-url-import-wrap',
  importButton: '.t3js-vidply-media-url-import',
  refreshButton: '.t3js-vidply-media-url-refresh',
  urlInput: '.t3js-vidply-media-url-input',
  status: '.t3js-vidply-media-url-status',
  mediaTypeSelect: 'select[name*="[media_type]"]',
};

/**
 * TYPO3 14+ exposes markFieldAsChanged on FormEngine directly;
 * TYPO3 13.4 keeps it on FormEngine.Validation.
 *
 * @param {HTMLElement} field
 */
function markFieldAsChanged(field) {
  if (typeof FormEngine.markFieldAsChanged === 'function') {
    FormEngine.markFieldAsChanged(field);
    return;
  }
  FormEngine.Validation?.markFieldAsChanged?.(field);
}

/**
 * @param {HTMLElement} wrap
 * @returns {ParentNode}
 */
function getRecordScope(wrap) {
  return wrap.closest('.form-irre-object') ?? wrap.closest('form') ?? document;
}

/**
 * @param {string} recordIdentifier
 * @returns {boolean}
 */
function isPersistedRecord(recordIdentifier) {
  return recordIdentifier !== '' && !String(recordIdentifier).startsWith('NEW');
}

/**
 * @param {HTMLElement} wrap
 * @param {string} fieldName
 * @returns {string|null}
 */
function resolveFormEngineFieldName(wrap, fieldName) {
  const itemName = wrap.dataset.itemName || '';
  if (itemName.includes(IMPORT_FIELD)) {
    return itemName.replace(IMPORT_FIELD, `[${fieldName}]`);
  }
  return null;
}

/**
 * @param {HTMLElement} wrap
 * @param {ParentNode} scope
 * @param {string} fieldName
 * @returns {HTMLElement|null}
 */
function findRecordField(wrap, scope, fieldName) {
  const formEngineName = resolveFormEngineFieldName(wrap, fieldName);
  if (formEngineName) {
    const byDataAttr = document.querySelector(`[data-formengine-input-name="${formEngineName}"]`);
    if (byDataAttr instanceof HTMLElement) {
      return byDataAttr;
    }
    const byName = document.querySelector(`[name="${formEngineName}"]`);
    if (byName instanceof HTMLElement) {
      return byName;
    }
  }

  const suffix = `[${fieldName}]`;
  for (const candidate of scope.querySelectorAll(
    `[data-formengine-input-name$="${suffix}"], input[name$="${suffix}"]:not([type="hidden"]), textarea[name$="${suffix}"]`
  )) {
    if (candidate instanceof HTMLElement) {
      return candidate;
    }
  }

  return null;
}

/**
 * @param {HTMLElement} wrap
 * @returns {string}
 */
function resolveFormFieldPrefix(wrap) {
  const itemName = wrap.dataset.itemName || '';
  if (itemName.includes(IMPORT_FIELD)) {
    return itemName.replace(IMPORT_FIELD, '');
  }
  return '';
}

/**
 * Resolve the file field web component and its data container.
 * TYPO3 14+ uses typo3-formengine-container-inline with attributes on the element.
 * TYPO3 13.4 uses typo3-formengine-container-files with attributes on an inner div.
 *
 * @param {HTMLElement} wrap
 * @param {ParentNode} scope
 * @param {string} fieldName
 * @returns {{ webComponent: HTMLElement|null, dataContainer: HTMLElement|null }}
 */
function resolveFileFieldNodes(wrap, scope, fieldName) {
  const prefix = resolveFormFieldPrefix(wrap);
  const expectedName = prefix ? `${prefix}[${fieldName}]` : '';
  const suffix = `[${fieldName}]`;

  for (const element of scope.querySelectorAll('typo3-formengine-container-inline[data-form-field]')) {
    const formField = element.getAttribute('data-form-field') || '';
    if ((expectedName !== '' && formField === expectedName) || formField.endsWith(suffix)) {
      return { webComponent: element, dataContainer: element };
    }
  }

  for (const element of scope.querySelectorAll('typo3-formengine-container-files')) {
    const inner = element.querySelector('[data-form-field]');
    if (!(inner instanceof HTMLElement)) {
      continue;
    }
    const formField = inner.getAttribute('data-form-field') || '';
    if ((expectedName !== '' && formField === expectedName) || formField.endsWith(suffix)) {
      return { webComponent: element, dataContainer: inner };
    }
  }

  const hiddenInput = scope.querySelector(`input.inlineRecord[name$="${suffix}"]`);
  if (hiddenInput instanceof HTMLInputElement) {
    const dataContainer = hiddenInput.closest('[data-object-group]');
    const webComponent = hiddenInput.closest(FILE_CONTAINER_SELECTORS);
    if (dataContainer instanceof HTMLElement || webComponent instanceof HTMLElement) {
      return {
        webComponent: webComponent instanceof HTMLElement ? webComponent : null,
        dataContainer: dataContainer instanceof HTMLElement ? dataContainer : webComponent,
      };
    }
  }

  return { webComponent: null, dataContainer: null };
}

/**
 * @param {ParentNode} scope
 * @param {string} fieldName
 * @param {HTMLElement|null} wrap
 * @returns {string|null}
 */
function findIrreObjectForField(fieldName, scope = document, wrap = null) {
  if (wrap instanceof HTMLElement) {
    const { dataContainer } = resolveFileFieldNodes(wrap, scope, fieldName);
    if (dataContainer?.dataset.objectGroup) {
      return dataContainer.dataset.objectGroup;
    }
  }

  const hiddenInput = scope.querySelector(`input.inlineRecord[name$="[${fieldName}]"]`);
  if (hiddenInput instanceof HTMLInputElement) {
    const dataContainer = hiddenInput.closest('[data-object-group]');
    if (dataContainer instanceof HTMLElement && dataContainer.dataset.objectGroup) {
      return dataContainer.dataset.objectGroup;
    }
  }

  for (const trigger of scope.querySelectorAll('[data-file-irre-object]')) {
    const irreObject = trigger.dataset.fileIrreObject || '';
    const fieldWrap = trigger.closest(
      '.form-group, .form-control-wrap, typo3-formengine-container-files, typo3-formengine-container-inline'
    );
    if (fieldWrap?.querySelector(`input.inlineRecord[name$="[${fieldName}]"]`)) {
      return irreObject;
    }
  }

  return null;
}

/**
 * @param {string} fieldName
 * @param {number} fileUid
 * @param {ParentNode} scope
 * @param {HTMLElement|null} wrap
 */
function insertFileRelation(fieldName, fileUid, scope = document, wrap = null) {
  const nodes = wrap instanceof HTMLElement
    ? resolveFileFieldNodes(wrap, scope, fieldName)
    : { webComponent: null, dataContainer: null };
  const irreObject = findIrreObjectForField(fieldName, scope, wrap);

  if (!irreObject) {
    return false;
  }

  if (nodes.webComponent && typeof nodes.webComponent.importRecord === 'function') {
    void nodes.webComponent.importRecord([irreObject, String(fileUid)]);
    return true;
  }

  MessageUtility.send({
    actionName: 'typo3:foreignRelation:insert',
    objectGroup: irreObject,
    table: 'sys_file',
    uid: fileUid,
  });

  return true;
}

/**
 * @param {string} fieldName
 * @param {number} fileUid
 * @param {ParentNode} scope
 * @param {HTMLElement|null} wrap
 * @param {number} attempts
 * @param {number} maxAttempts
 */
function insertFileRelationWithRetry(fieldName, fileUid, scope, wrap = null, attempts = 0, maxAttempts = 80) {
  if (insertFileRelation(fieldName, fileUid, scope, wrap)) {
    return Promise.resolve(true);
  }

  if (attempts >= maxAttempts) {
    return Promise.resolve(false);
  }

  return new Promise((resolve) => {
    window.setTimeout(() => {
      resolve(insertFileRelationWithRetry(fieldName, fileUid, scope, wrap, attempts + 1, maxAttempts));
    }, 150);
  });
}

/**
 * @param {HTMLElement} input
 * @param {string} value
 */
function setFieldValue(input, value) {
  input.value = value;
  input.dispatchEvent(new Event('change', { bubbles: true }));
  markFieldAsChanged(input);
}

/**
 * @param {HTMLElement} wrap
 * @param {ParentNode} scope
 * @param {string} fieldName
 * @param {string|number} value
 */
function applyFieldValue(wrap, scope, fieldName, value) {
  if (value === null || value === undefined) {
    return;
  }

  const stringValue = String(value).trim();
  if (stringValue === '' || stringValue === '0') {
    return;
  }

  const input = findRecordField(wrap, scope, fieldName);
  if (!(input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement)) {
    return;
  }

  if (input.value.trim() !== '' && input.value.trim() !== '0') {
    return;
  }

  setFieldValue(input, stringValue);
}

/**
 * @param {HTMLElement} wrap
 * @param {{
 *   mediaType?: string,
 *   mediaFileUid?: number,
 *   posterFileUid?: number,
 *   title?: string,
 *   artist?: string,
 *   description?: string,
 *   duration?: number,
 * }} result
 * @param {{ silentMediaType?: boolean }} [options]
 * @returns {Promise<boolean>}
 */
async function applyImportResultImmediate(wrap, result, options = {}) {
  const silentMediaType = options.silentMediaType ?? false;
  const scope = getRecordScope(wrap);

  applyFieldValue(wrap, scope, 'title', result.title || '');
  applyFieldValue(wrap, scope, 'artist', result.artist || '');
  applyFieldValue(wrap, scope, 'description', result.description || '');
  applyFieldValue(wrap, scope, 'duration', result.duration || 0);

  let mediaFileInserted = true;
  if (result.mediaFileUid) {
    mediaFileInserted = await insertFileRelationWithRetry('media_file', result.mediaFileUid, scope, wrap);
  }

  if (result.posterFileUid) {
    await insertFileRelationWithRetry('poster', result.posterFileUid, scope, wrap);
  }

  if (result.mediaType) {
    updateMediaTypeField(wrap, result.mediaType, { triggerChange: !silentMediaType });
  }

  return mediaFileInserted;
}

/**
 * @param {HTMLElement} wrap
 * @param {number} attempts
 */
function applyPendingFileInserts(wrap, attempts = 0) {
  const raw = wrap.dataset.vidplyPendingImport;
  if (!raw) {
    return;
  }

  let pending;
  try {
    pending = JSON.parse(raw);
  } catch {
    return;
  }

  const scope = getRecordScope(wrap);

  if (pending.mediaFileUid) {
    const { webComponent } = resolveFileFieldNodes(wrap, scope, 'media_file');
    if (!webComponent && attempts < 80) {
      window.setTimeout(() => applyPendingFileInserts(wrap, attempts + 1), 150);
      return;
    }
  }

  void applyImportResultImmediate(wrap, pending, { silentMediaType: true });
  delete wrap.dataset.vidplyPendingImport;
}

/**
 * @param {HTMLElement} wrap
 * @returns {{recordIdentifier: string, tableName: string, pid: number, currentMediaType: string}}
 */
function readWrapConfig(wrap) {
  return {
    recordIdentifier: wrap.dataset.recordIdentifier || '',
    tableName: wrap.dataset.tableName || 'tx_mpcvidply_media',
    pid: parseInt(wrap.dataset.pid || '0', 10),
    currentMediaType: wrap.dataset.currentMediaType || 'video',
  };
}

/**
 * @param {HTMLElement} wrap
 * @param {string} message
 * @param {'info'|'success'|'warning'|'error'} severity
 */
function setStatus(wrap, message, severity = 'info') {
  const status = wrap.querySelector(SELECTORS.status);
  if (!status) {
    return;
  }
  status.textContent = message;
  status.className = `form-text mt-1 t3js-vidply-media-url-status text-${severity === 'error' ? 'danger' : severity}`;
}

/**
 * @param {HTMLElement} wrap
 * @param {string} mediaType
 * @param {{ triggerChange?: boolean }} [options]
 */
function updateMediaTypeField(wrap, mediaType, options = {}) {
  const triggerChange = options.triggerChange ?? true;
  const scope = getRecordScope(wrap);
  const formEngineName = resolveFormEngineFieldName(wrap, 'media_type');
  let select = null;
  let hidden = null;

  if (formEngineName) {
    const candidate = document.querySelector(`[data-formengine-input-name="${formEngineName}"]`);
    if (candidate instanceof HTMLSelectElement) {
      select = candidate;
    } else if (candidate instanceof HTMLInputElement) {
      hidden = candidate;
    }
    const named = document.querySelector(`select[name="${formEngineName}"]`);
    if (named instanceof HTMLSelectElement) {
      select = named;
    }
  }

  if (!select) {
    select = scope.querySelector(SELECTORS.mediaTypeSelect);
  }
  if (!hidden) {
    hidden = scope.querySelector('[data-formengine-input-name$="[media_type]"]');
  }

  if (select instanceof HTMLSelectElement && select.value !== mediaType) {
    select.value = mediaType;
    markFieldAsChanged(select);
    if (triggerChange) {
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  if (hidden instanceof HTMLInputElement && hidden !== select && hidden.value !== mediaType) {
    hidden.value = mediaType;
    markFieldAsChanged(hidden);
    if (triggerChange) {
      hidden.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  wrap.dataset.currentMediaType = mediaType;
}

/**
 * Reload the form after media_type change (persisted records only).
 *
 * @param {HTMLElement} wrap
 * @param {string} mediaType
 */
function triggerMediaTypeReload(wrap, mediaType) {
  const scope = getRecordScope(wrap);
  const select = scope.querySelector(SELECTORS.mediaTypeSelect);
  if (!(select instanceof HTMLSelectElement)) {
    window.location.reload();
    return;
  }

  updateMediaTypeField(wrap, mediaType, { triggerChange: false });

  FormEngine.processOnFieldChange([{
    name: 'typo3-backend-form-reload',
    data: { confirmation: false },
  }], select);
}

/**
 * @param {HTMLElement} wrap
 */
async function handleImport(wrap) {
  const input = wrap.querySelector(SELECTORS.urlInput);
  const url = input?.value?.trim() || '';
  if (url === '') {
    setStatus(wrap, 'Please paste a media URL.', 'error');
    return;
  }

  const config = readWrapConfig(wrap);
  setStatus(wrap, 'Importing…', 'info');

  try {
    if (!TYPO3.settings.ajaxUrls?.mpc_vidply_media_import_url) {
      setStatus(wrap, 'Import endpoint is not registered. Clear all caches.', 'error');
      return;
    }

    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.mpc_vidply_media_import_url).post({
      url,
      pid: config.pid,
      recordIdentifier: config.recordIdentifier,
      tableName: config.tableName,
    });
    const result = await response.resolve();

    if (!result.success) {
      setStatus(wrap, result.errorMessage || 'Import failed.', 'error');
      Notification.error('Import failed', result.errorMessage || '');
      return;
    }

    if (result.detectedLabel) {
      Notification.success('Detected', result.detectedLabel);
    }

    const typeChanged = config.currentMediaType !== result.mediaType;
    const isNewRecord = !isPersistedRecord(config.recordIdentifier);

    const mediaFileInserted = await applyImportResultImmediate(wrap, result, {
      silentMediaType: isNewRecord || typeChanged,
    });

    if (typeChanged && !isNewRecord) {
      triggerMediaTypeReload(wrap, result.mediaType);
      return;
    }

    if (result.mediaFileUid && !mediaFileInserted) {
      setStatus(
        wrap,
        'Metadata imported, but media file/poster could not be linked automatically. Save and add them manually, or try again.',
        'warning'
      );
      Notification.warning('Import partially complete', 'Media file or poster could not be linked automatically.');
      return;
    }

    setStatus(wrap, 'Import complete. Save the record to persist.', 'success');
  } catch (error) {
    setStatus(wrap, 'Import request failed.', 'error');
    Notification.error('Import failed', String(error));
  }
}

/**
 * @param {HTMLElement} wrap
 * @param {HTMLButtonElement} button
 */
async function handleRefresh(wrap, button) {
  const fileUid = parseInt(button.dataset.fileUid || '0', 10);
  if (fileUid <= 0) {
    setStatus(wrap, 'No linked media file to refresh.', 'error');
    return;
  }

  const config = readWrapConfig(wrap);
  const scope = getRecordScope(wrap);
  setStatus(wrap, 'Refreshing metadata…', 'info');

  try {
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.mpc_vidply_media_refresh_metadata).post({
      fileUid,
      currentMediaType: config.currentMediaType,
      recordIdentifier: config.recordIdentifier,
      tableName: config.tableName,
    });
    const result = await response.resolve();

    if (!result.success) {
      setStatus(wrap, result.errorMessage || 'Refresh failed.', 'error');
      Notification.error('Refresh failed', result.errorMessage || '');
      return;
    }

    if (result.typeMismatchWarning) {
      Notification.warning('Type mismatch', result.typeMismatchWarning);
    }

    applyFieldValue(wrap, scope, 'title', result.title || '');
    applyFieldValue(wrap, scope, 'artist', result.artist || '');
    applyFieldValue(wrap, scope, 'description', result.description || '');
    applyFieldValue(wrap, scope, 'duration', result.duration || 0);

    if (result.posterFileUid) {
      insertFileRelationWithRetry('poster', result.posterFileUid, scope, wrap);
    }

    setStatus(wrap, 'Metadata refreshed.', 'success');
    Notification.success('Metadata refreshed', result.detectedLabel || '');
  } catch (error) {
    setStatus(wrap, 'Refresh request failed.', 'error');
    Notification.error('Refresh failed', String(error));
  }
}

function registerEvents() {
  if (registerEvents.initialized) {
    return;
  }
  registerEvents.initialized = true;

  new RegularEvent('click', (event, button) => {
    event.preventDefault();
    const wrap = button.closest(SELECTORS.wrap);
    if (wrap) {
      handleImport(wrap);
    }
  }).delegateTo(document, SELECTORS.importButton);

  new RegularEvent('click', (event, button) => {
    event.preventDefault();
    const wrap = button.closest(SELECTORS.wrap);
    if (wrap) {
      handleRefresh(wrap, button);
    }
  }).delegateTo(document, SELECTORS.refreshButton);
}
registerEvents.initialized = false;

class MediaUrlImportModule {
  constructor() {
    DocumentService.ready().then(async () => {
      if (typeof FormEngine.ready === 'function') {
        await FormEngine.ready();
      }
      document.querySelectorAll(SELECTORS.wrap).forEach((wrap) => {
        applyPendingFileInserts(wrap);
      });
      registerEvents();
    });
  }
}

export default new MediaUrlImportModule();
