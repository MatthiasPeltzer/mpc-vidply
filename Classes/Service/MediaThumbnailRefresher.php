<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;

/**
 * Makes TYPO3 build new thumbnails for a container file after the poster of
 * its media record changed. Core only reprocesses when the file itself
 * changes, and the thumbnail name (and URL) is derived from the file's
 * modification time, so both the processed files and that time are renewed:
 * otherwise browsers keep showing the cached thumbnail of the old poster.
 *
 * The time is renewed by writing the unchanged URL back through the storage,
 * which checks the editor's write permission and works on every driver. An
 * editor without write access still gets fresh thumbnails, only under the
 * old URL.
 */
final readonly class MediaThumbnailRefresher
{
    public function __construct(
        private ProcessedFileRepository $processedFileRepository,
    ) {}

    public function refresh(File $file): void
    {
        foreach ($this->processedFileRepository->findAllByOriginalFile($file) as $processedFile) {
            try {
                $processedFile->delete(true);
            } catch (\Throwable) {
            }
        }

        try {
            $file->getStorage()->setFileContents($file, $file->getContents());
        } catch (\Throwable) {
        }
    }
}
