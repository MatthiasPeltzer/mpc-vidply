<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Streaming;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\Index\Indexer;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Puts conversion output into FAL and removes it again.
 *
 * Only manifests, progressive MP4s and posters are indexed; segments are
 * fetched by the player through the manifest and would only flood sys_file.
 * On a Local storage the tree is moved into place directly and a re-run keeps
 * the sys_file uids of the indexed files, so the variant's references survive.
 */
final readonly class StreamOutputStorage
{
    public function __construct(
        private ResourceFactory $resourceFactory,
        private StorageRepository $storageRepository,
        private StreamingSettings $settings,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * The configured target folder, created when it does not exist yet.
     *
     * @throws \RuntimeException
     */
    public function resolveTargetFolder(): Folder
    {
        $combinedIdentifier = $this->settings->get()->targetFolder;
        if (preg_match('/^(\d+):(.*)$/', $combinedIdentifier, $matches) !== 1) {
            throw new \RuntimeException(sprintf('Invalid target folder "%s"; expected "<storage uid>:/path/".', $combinedIdentifier), 1762156811);
        }

        $storage = $this->storageRepository->findByUid((int)$matches[1]);
        if ($storage === null || !$storage->isOnline() || !$storage->isWritable()) {
            throw new \RuntimeException(sprintf('Storage %d is missing, offline or not writable.', (int)$matches[1]), 1762156812);
        }

        $evaluatePermissions = $storage->getEvaluatePermissions();
        $storage->setEvaluatePermissions(false);
        try {
            $folder = $storage->getRootLevelFolder(false);
            foreach (GeneralUtility::trimExplode('/', $matches[2], true) as $segment) {
                $folder = $storage->hasFolderInFolder($segment, $folder)
                    ? $storage->getFolderInFolder($segment, $folder, true)
                    : $storage->createFolder($segment, $folder);
            }
        } finally {
            $storage->setEvaluatePermissions($evaluatePermissions);
        }

        return $folder;
    }

    /**
     * Replace `<target>/<folderName>/` with the contents of `$localDirectory`
     * (which is consumed) and index `$indexPaths`.
     *
     * @param list<string> $indexPaths Paths relative to `$localDirectory`
     * @return array{folder: string, files: array<string, File>} Combined folder identifier and indexed files by relative path
     * @throws \RuntimeException
     */
    public function publish(string $localDirectory, string $folderName, array $indexPaths): array
    {
        $targetFolder = $this->resolveTargetFolder();
        $storage = $targetFolder->getStorage();
        $evaluatePermissions = $storage->getEvaluatePermissions();
        $storage->setEvaluatePermissions(false);

        try {
            $files = $this->isLocal($storage)
                ? $this->publishLocal($storage, $targetFolder, $localDirectory, $folderName, $indexPaths)
                : $this->publishGeneric($storage, $targetFolder, $localDirectory, $folderName, $indexPaths);
        } finally {
            $storage->setEvaluatePermissions($evaluatePermissions);
        }

        return [
            'folder' => $storage->getUid() . ':' . rtrim($targetFolder->getIdentifier(), '/') . '/' . $folderName . '/',
            'files' => $files,
        ];
    }

    /**
     * Remove an output folder written by `publish()`, including its index
     * entries. Unknown or already removed folders are ignored.
     */
    public function delete(string $combinedFolderIdentifier): void
    {
        if (preg_match('/^(\d+):(\/.+\/)$/', $combinedFolderIdentifier, $matches) !== 1) {
            return;
        }

        $storage = $this->storageRepository->findByUid((int)$matches[1]);
        if ($storage === null) {
            return;
        }
        $identifier = $matches[2];
        $evaluatePermissions = $storage->getEvaluatePermissions();
        $storage->setEvaluatePermissions(false);

        try {
            if (!$storage->hasFolder($identifier)) {
                return;
            }
            if (!$this->isLocal($storage)) {
                $storage->deleteFolder($storage->getFolder($identifier), true);
                return;
            }
            foreach ($this->indexedFileUids($storage, $identifier) as $fileUid) {
                try {
                    $file = $this->resourceFactory->getFileObject($fileUid);
                    if ($storage->hasFile($file->getIdentifier())) {
                        $storage->deleteFile($file);
                    }
                } catch (\Throwable) {
                    // Already gone on disk; the folder removal below finishes the job.
                }
            }
            GeneralUtility::rmdir($this->absolutePath($storage, $identifier), true);
        } finally {
            $storage->setEvaluatePermissions($evaluatePermissions);
        }
    }

    /**
     * @param list<string> $indexPaths
     * @return array<string, File>
     */
    private function publishLocal(ResourceStorage $storage, Folder $targetFolder, string $localDirectory, string $folderName, array $indexPaths): array
    {
        $folderIdentifier = rtrim($targetFolder->getIdentifier(), '/') . '/' . $folderName . '/';
        $absoluteTarget = $this->absolutePath($storage, $folderIdentifier);

        if (is_dir($absoluteTarget)) {
            GeneralUtility::rmdir($absoluteTarget, true);
        }
        $this->moveTree(rtrim($localDirectory, '/'), rtrim($absoluteTarget, '/'));

        $indexer = GeneralUtility::makeInstance(Indexer::class, $storage);
        $files = [];
        $identifiers = [];
        foreach ($indexPaths as $relativePath) {
            $fileIdentifier = $folderIdentifier . ltrim($relativePath, '/');
            $identifiers[] = $fileIdentifier;
            $file = $storage->getFileByIdentifier($fileIdentifier);
            if (!$file instanceof File) {
                throw new \RuntimeException(sprintf('Could not index "%s".', $fileIdentifier), 1762156813);
            }
            // A re-run reuses the index entry; refresh size, hash and mime type.
            $indexer->updateIndexEntry($file);
            $files[$relativePath] = $file;
        }

        $this->markStaleIndexEntriesMissing($storage, $folderIdentifier, $identifiers);

        return $files;
    }

    /**
     * @param list<string> $indexPaths
     * @return array<string, File>
     */
    private function publishGeneric(ResourceStorage $storage, Folder $targetFolder, string $localDirectory, string $folderName, array $indexPaths): array
    {
        if ($storage->hasFolderInFolder($folderName, $targetFolder)) {
            $storage->deleteFolder($storage->getFolderInFolder($folderName, $targetFolder, true), true);
        }
        $outputFolder = $storage->createFolder($folderName, $targetFolder);

        $files = [];
        $localDirectory = rtrim($localDirectory, '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || $item->isDir()) {
                continue;
            }
            $relativePath = substr($item->getPathname(), strlen($localDirectory) + 1);
            $relativePath = str_replace('\\', '/', $relativePath);
            $folder = $outputFolder;
            foreach (array_slice(explode('/', $relativePath), 0, -1) as $segment) {
                $folder = $storage->hasFolderInFolder($segment, $folder)
                    ? $storage->getFolderInFolder($segment, $folder, true)
                    : $storage->createFolder($segment, $folder);
            }
            $file = $storage->addFile($item->getPathname(), $folder, $item->getFilename(), DuplicationBehavior::REPLACE);
            if (in_array($relativePath, $indexPaths, true)) {
                $files[$relativePath] = $file;
            }
        }

        return $files;
    }

    private function moveTree(string $source, string $target): void
    {
        GeneralUtility::mkdir_deep($target);

        foreach (new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS) as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $destination = $target . '/' . $item->getFilename();
            if ($item->isDir()) {
                $this->moveTree($item->getPathname(), $destination);
                continue;
            }
            // var/ and fileadmin/ may sit on different mounts, where rename() fails.
            if (!@rename($item->getPathname(), $destination)) {
                if (!@copy($item->getPathname(), $destination)) {
                    throw new \RuntimeException(sprintf('Could not move "%s" into the target folder.', $item->getFilename()), 1762156815);
                }
                @unlink($item->getPathname());
            }
            GeneralUtility::fixPermissions($destination);
        }
        GeneralUtility::fixPermissions($target);
    }

    /**
     * @param list<string> $keepIdentifiers
     */
    private function markStaleIndexEntriesMissing(ResourceStorage $storage, string $folderIdentifier, array $keepIdentifiers): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder
            ->update('sys_file')
            ->set('missing', 1)
            ->where(
                $queryBuilder->expr()->eq('storage', $queryBuilder->createNamedParameter($storage->getUid(), Connection::PARAM_INT)),
                $queryBuilder->expr()->like(
                    'identifier',
                    $queryBuilder->createNamedParameter($queryBuilder->escapeLikeWildcards($folderIdentifier) . '%')
                )
            );
        if ($keepIdentifiers !== []) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->notIn(
                    'identifier',
                    $queryBuilder->createNamedParameter($keepIdentifiers, Connection::PARAM_STR_ARRAY)
                )
            );
        }
        $queryBuilder->executeStatement();
    }

    /**
     * @return list<int>
     */
    private function indexedFileUids(ResourceStorage $storage, string $folderIdentifier): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $rows = $queryBuilder
            ->select('uid')
            ->from('sys_file')
            ->where(
                $queryBuilder->expr()->eq('storage', $queryBuilder->createNamedParameter($storage->getUid(), Connection::PARAM_INT)),
                $queryBuilder->expr()->like(
                    'identifier',
                    $queryBuilder->createNamedParameter($queryBuilder->escapeLikeWildcards($folderIdentifier) . '%')
                )
            )
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map(intval(...), $rows));
    }

    private function isLocal(ResourceStorage $storage): bool
    {
        return $storage->getDriverType() === 'Local';
    }

    private function absolutePath(ResourceStorage $storage, string $identifier): string
    {
        $configuration = $storage->getConfiguration();
        $basePath = (string)($configuration['basePath'] ?? '');
        if (($configuration['pathType'] ?? 'relative') === 'relative') {
            $basePath = Environment::getPublicPath() . '/' . $basePath;
        }

        return rtrim(PathUtility::getCanonicalPath($basePath), '/') . '/' . ltrim($identifier, '/');
    }
}
