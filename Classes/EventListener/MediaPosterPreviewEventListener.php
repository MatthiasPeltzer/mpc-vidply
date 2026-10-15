<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\EventListener;

use Mpc\MpcVidply\Service\MediaPosterResolver;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\OnlineMedia\Event\AfterVideoPreviewFetchedEvent;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Uses the poster of the VidPly media record as the FAL preview (file list
 * thumbnail, element browser) of the container files VidPly provides. The
 * helpers themselves only know the remote URL and fall back to the extension
 * icon; YouTube and Vimeo keep the thumbnail core downloads for them.
 */
final readonly class MediaPosterPreviewEventListener
{
    public function __construct(
        private MediaPosterResolver $posterResolver,
        private ResourceFactory $resourceFactory,
    ) {}

    public function __invoke(AfterVideoPreviewFetchedEvent $event): void
    {
        $file = $event->getFile();
        if (!in_array($file->getExtension(), MediaPosterResolver::CONTAINER_EXTENSIONS, true)) {
            return;
        }

        $posterUid = $this->posterResolver->findPosterFileUid($file->getUid());
        if ($posterUid <= 0) {
            return;
        }

        try {
            $poster = $this->resourceFactory->getFileObject($posterUid);
            if ($poster->isMissing() || !$poster->isImage()) {
                return;
            }
            $posterPath = $this->getLocalPath($poster);
        } catch (\Throwable) {
            return;
        }

        if ($posterPath !== '' && is_file($posterPath)) {
            $event->setPreviewImageFilename($posterPath);
        }
    }

    /**
     * Posters on remote storages are copied once per content into the folder
     * core uses for online media previews; a temporary copy per preview run
     * would never be cleaned up.
     */
    private function getLocalPath(File $poster): string
    {
        if ($poster->getStorage()->getDriverType() === 'Local') {
            return $poster->getForLocalProcessing(false);
        }

        $sha1 = $poster->getSha1();
        if ($sha1 === '') {
            return '';
        }
        $path = Environment::getPublicPath() . '/typo3temp/assets/online_media/vidply_poster_'
            . $sha1 . '.' . strtolower($poster->getExtension());
        if (!is_file($path)) {
            GeneralUtility::writeFileToTypo3tempDir($path, $poster->getContents());
        }

        return $path;
    }
}
