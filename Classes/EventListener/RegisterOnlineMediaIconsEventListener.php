<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\EventListener;

use TYPO3\CMS\Core\Core\Event\BootCompletedEvent;
use TYPO3\CMS\Core\Imaging\IconRegistry;

final readonly class RegisterOnlineMediaIconsEventListener
{
    public function __construct(
        private IconRegistry $iconRegistry
    ) {}

    public function __invoke(BootCompletedEvent $event): void
    {
        // Register external video/audio online media container file extensions
        $this->iconRegistry->registerFileExtension('externalvideo', 'mpc_vidply-plugin');
        $this->iconRegistry->registerFileExtension('externalaudio', 'mpc_vidply-plugin');
        $this->iconRegistry->registerFileExtension('soundcloud', 'mpc_vidply-plugin');
        $this->iconRegistry->registerFileExtension('hls', 'mpc_vidply-plugin');
        $this->iconRegistry->registerFileExtension('dash', 'mpc_vidply-plugin');
    }
}
