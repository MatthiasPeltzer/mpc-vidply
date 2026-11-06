<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service\Streaming;

use Mpc\MpcVidply\Dto\StreamingConfiguration;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Reads the streaming settings of EXT:mpc_vidply once per request.
 */
final class StreamingSettings
{
    private ?StreamingConfiguration $configuration = null;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function get(): StreamingConfiguration
    {
        if ($this->configuration === null) {
            try {
                $values = $this->extensionConfiguration->get('mpc_vidply');
            } catch (\Throwable) {
                $values = [];
            }
            $this->configuration = StreamingConfiguration::fromArray(is_array($values) ? $values : []);
        }

        return $this->configuration;
    }

    public function isEnabled(): bool
    {
        return $this->get()->enabled;
    }
}
