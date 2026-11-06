<?php

declare(strict_types=1);

use Mpc\MpcVidply\Report\FfmpegStatusProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Reports\StatusProviderInterface;

return static function (ContainerConfigurator $container): void {
    // EXT:reports is optional (suggest); its interface only exists when installed.
    if (interface_exists(StatusProviderInterface::class)) {
        $container->services()
            ->set(FfmpegStatusProvider::class)
            ->autowire()
            ->autoconfigure();
    }
};
