<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\EventListener;

use Mpc\MpcVidply\Service\MediaPreviewDetailPageResolver;
use TYPO3\CMS\Backend\Routing\Event\BeforePagePreviewUriGeneratedEvent;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Routing\UnableToLinkToPageException;

/**
 * Rewrites preview links for {@see tx_mpcvidply_media} records to the configured
 * VidPly detail page with the correct {@code media} query parameter.
 */
final readonly class MediaPreviewUriEventListener
{
    private const MEDIA_TABLE = 'tx_mpcvidply_media';

    public function __construct(
        private MediaPreviewDetailPageResolver $detailPageResolver,
    ) {}

    public function __invoke(BeforePagePreviewUriGeneratedEvent $event): void
    {
        $params = $event->getAdditionalQueryParameters();
        $mediaIdentifier = $params['media'] ?? null;
        if (!is_string($mediaIdentifier) && !is_int($mediaIdentifier)) {
            return;
        }

        $mediaIdentifier = trim((string)$mediaIdentifier);
        if ($mediaIdentifier === '' || !ctype_digit($mediaIdentifier)) {
            return;
        }

        $mediaUid = (int)$mediaIdentifier;
        if ($mediaUid <= 0) {
            return;
        }

        $record = BackendUtility::getRecord(self::MEDIA_TABLE, $mediaUid);
        if (!is_array($record) || (int)($record['uid'] ?? 0) <= 0) {
            return;
        }

        $defaultMediaUid = $this->resolveDefaultMediaUid($record);
        $storagePid = (int)($record['pid'] ?? 0);
        $detailPageUid = $this->detailPageResolver->resolveDetailPageUidForMedia($defaultMediaUid, $storagePid);
        if ($detailPageUid <= 0) {
            throw new UnableToLinkToPageException(
                'No VidPly detail page is configured for previewing media records.',
                1742369201
            );
        }

        $languageId = (int)($params['_language'] ?? $event->getLanguageId());
        $slug = trim((string)($record['slug'] ?? ''));
        $queryParameters = [
            'media' => $slug !== '' ? $slug : (string)$defaultMediaUid,
        ];
        if ($languageId > 0) {
            $queryParameters['_language'] = $languageId;
        }

        $event->setPageId($detailPageUid);
        $event->setLanguageId($languageId);
        $event->setAdditionalQueryParameters($queryParameters);
        $event->setRootline(BackendUtility::BEgetRootLine($detailPageUid));
        $event->setSection('');
    }

    /**
     * @param array<string, mixed> $record
     */
    private function resolveDefaultMediaUid(array $record): int
    {
        $parent = (int)($record['l10n_parent'] ?? 0);

        return $parent > 0 ? $parent : (int)($record['uid'] ?? 0);
    }
}
