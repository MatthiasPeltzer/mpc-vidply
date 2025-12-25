<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\EventListener;

use TYPO3\CMS\Core\Core\Event\BootCompletedEvent;
use TYPO3\CMS\Core\Imaging\IconRegistry;

final class RegisterOnlineMediaIconsEventListener
{
    public function __construct(
        private readonly IconRegistry $iconRegistry
    ) {
    }

    public function __invoke(BootCompletedEvent $event): void
    {
        // Register external video/audio online media container file extensions
        $this->iconRegistry->registerFileExtension('externalvideo', 'mimetypes-media-video');
        $this->iconRegistry->registerFileExtension('externalaudio', 'mimetypes-media-audio');
        $this->iconRegistry->registerFileExtension('soundcloud', 'mimetypes-media-audio');
        $this->iconRegistry->registerFileExtension('hls', 'mimetypes-media-video');
    }
}


