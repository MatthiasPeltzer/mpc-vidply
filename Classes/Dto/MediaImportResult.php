<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Dto;

final readonly class MediaImportResult
{
    public function __construct(
        public bool $success,
        public string $mediaType = '',
        public int $mediaFileUid = 0,
        public string $title = '',
        public string $artist = '',
        public string $description = '',
        public int $duration = 0,
        public int $posterFileUid = 0,
        public string $detectedLabel = '',
        public string $errorMessage = '',
        public ?string $typeMismatchWarning = null,
    ) {}

    public static function ok(
        string $mediaType,
        int $mediaFileUid,
        string $title = '',
        string $artist = '',
        string $description = '',
        int $duration = 0,
        int $posterFileUid = 0,
        string $detectedLabel = '',
    ): self {
        return new self(
            success: true,
            mediaType: $mediaType,
            mediaFileUid: $mediaFileUid,
            title: $title,
            artist: $artist,
            description: $description,
            duration: $duration,
            posterFileUid: $posterFileUid,
            detectedLabel: $detectedLabel,
        );
    }

    public static function fail(string $errorMessage): self
    {
        return new self(success: false, errorMessage: $errorMessage);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'mediaType' => $this->mediaType,
            'mediaFileUid' => $this->mediaFileUid,
            'title' => $this->title,
            'artist' => $this->artist,
            'description' => $this->description,
            'duration' => $this->duration,
            'posterFileUid' => $this->posterFileUid,
            'detectedLabel' => $this->detectedLabel,
            'errorMessage' => $this->errorMessage,
            'typeMismatchWarning' => $this->typeMismatchWarning,
        ];
    }
}
