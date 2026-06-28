<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Backend\Controller;

use Mpc\MpcVidply\Enums\MediaType;
use Mpc\MpcVidply\Service\MediaFromUrlService;
use Mpc\MpcVidply\Service\MediaUrlImportSessionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

#[AsController]
final readonly class MediaImportAjaxController
{
    public function __construct(
        private MediaFromUrlService $mediaFromUrlService,
        private MediaUrlImportSessionService $sessionService,
        private DefaultUploadFolderResolver $uploadFolderResolver,
        private ResourceFactory $resourceFactory,
    ) {}

    public function importAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->canModifyMediaRecords()) {
            return new JsonResponse(['success' => false, 'errorMessage' => 'Access denied.'], 403);
        }

        $body = $request->getParsedBody();
        $url = trim((string)($body['url'] ?? ''));
        $pid = (int)($body['pid'] ?? 0);
        $recordIdentifier = trim((string)($body['recordIdentifier'] ?? ''));
        $tableName = trim((string)($body['tableName'] ?? 'tx_mpcvidply_media'));

        if ($url === '') {
            return new JsonResponse(['success' => false, 'errorMessage' => 'Please paste a media URL.']);
        }

        if ($tableName !== 'tx_mpcvidply_media') {
            return new JsonResponse(['success' => false, 'errorMessage' => 'Invalid table.']);
        }

        if ($pid <= 0 || !$this->canAccessStoragePage($pid)) {
            return new JsonResponse(['success' => false, 'errorMessage' => 'Invalid or inaccessible storage folder.']);
        }

        $targetFolder = $this->uploadFolderResolver->resolve(
            $this->getBackendUser(),
            $pid,
            $tableName,
            'media_file'
        );
        if (!$targetFolder instanceof \TYPO3\CMS\Core\Resource\Folder) {
            return new JsonResponse(['success' => false, 'errorMessage' => 'Could not resolve an upload folder for this record.']);
        }

        $result = $this->mediaFromUrlService->import($url, $targetFolder);
        if (!$result->success) {
            return new JsonResponse($result->toArray());
        }

        if ($recordIdentifier !== '') {
            $recordKey = $this->sessionService->buildRecordKey($tableName, $recordIdentifier);
            $this->sessionService->store($recordKey, [
                'mediaType' => $result->mediaType,
                'mediaFileUid' => $result->mediaFileUid,
                'title' => $result->title,
                'artist' => $result->artist,
                'description' => $result->description,
                'duration' => $result->duration,
                'posterFileUid' => $result->posterFileUid,
                'detectedLabel' => $result->detectedLabel,
            ]);
        }

        return new JsonResponse($result->toArray());
    }

    public function refreshAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->canModifyMediaRecords()) {
            return new JsonResponse(['success' => false, 'errorMessage' => 'Access denied.'], 403);
        }

        $body = $request->getParsedBody();
        $fileUid = (int)($body['fileUid'] ?? 0);
        $currentMediaType = trim((string)($body['currentMediaType'] ?? ''));
        $recordIdentifier = trim((string)($body['recordIdentifier'] ?? ''));
        $tableName = trim((string)($body['tableName'] ?? 'tx_mpcvidply_media'));

        if ($fileUid <= 0) {
            return new JsonResponse(['success' => false, 'errorMessage' => 'No media file linked to refresh.']);
        }

        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
        } catch (\Throwable) {
            return new JsonResponse(['success' => false, 'errorMessage' => 'Media file not found.']);
        }

        if (!$file instanceof File) {
            return new JsonResponse(['success' => false, 'errorMessage' => 'Media file not found.']);
        }

        $mediaType = MediaType::tryFrom($currentMediaType);
        $result = $this->mediaFromUrlService->refreshMetadata($file, $mediaType);

        if (!$result->success) {
            return new JsonResponse($result->toArray());
        }

        if ($recordIdentifier !== '') {
            $recordKey = $this->sessionService->buildRecordKey($tableName, $recordIdentifier);
            $this->sessionService->store($recordKey, [
                'mediaType' => $currentMediaType !== '' ? $currentMediaType : $result->mediaType,
                'mediaFileUid' => $result->mediaFileUid,
                'title' => $result->title,
                'artist' => $result->artist,
                'description' => $result->description,
                'duration' => $result->duration,
                'posterFileUid' => $result->posterFileUid,
                'detectedLabel' => $result->detectedLabel,
                'refreshOnly' => true,
                'typeMismatchWarning' => $result->typeMismatchWarning,
            ]);
        }

        return new JsonResponse($result->toArray());
    }

    private function canModifyMediaRecords(): bool
    {
        return $this->getBackendUser()->check('tables_modify', 'tx_mpcvidply_media');
    }

    private function canAccessStoragePage(int $pid): bool
    {
        $pageRecord = BackendUtility::getRecord('pages', $pid);
        if (!is_array($pageRecord)) {
            return false;
        }

        return BackendUtility::readPageAccess($pageRecord, $this->getBackendUser()->getPagePermsClause(1)) !== false;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
