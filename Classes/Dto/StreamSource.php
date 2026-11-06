<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Dto;

/**
 * One local video file of a media record that the conversion turns into a stream.
 */
final readonly class StreamSource
{
    public const ROLE_MAIN = 'main';
    public const ROLE_AUDIO_DESCRIPTION = 'audio_description';
    public const ROLE_SIGN_LANGUAGE = 'sign_language';

    public function __construct(
        public string $role,
        public string $fieldName,
        public int $referenceUid,
        public int $fileUid,
        public string $fileName,
        public string $sha1,
    ) {}
}
