<?php

declare(strict_types=1);

namespace Mpc\MpcVidply\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Stores pending URL-import field values in the backend user session
 * until FormEngine reload applies them.
 */
final class MediaUrlImportSessionService
{
    private const SESSION_KEY = 'mpc_vidply_pending_import';
    private const POSTER_SESSION_KEY = 'mpc_vidply_pending_poster';

    /**
     * @param array<string, mixed> $data
     */
    public function store(string $recordKey, array $data): void
    {
        $session = $this->getSessionData();
        $session[$recordKey] = $data;
        $this->getBackendUser()->setSessionData(self::SESSION_KEY, $session);

        if (!empty($data['posterFileUid'])) {
            $this->storePendingPoster($recordKey, (int)$data['posterFileUid']);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pull(string $recordKey): ?array
    {
        $session = $this->getSessionData();
        if (!isset($session[$recordKey]) || !is_array($session[$recordKey])) {
            return null;
        }

        $data = $session[$recordKey];
        unset($session[$recordKey]);
        $this->getBackendUser()->setSessionData(self::SESSION_KEY, $session);

        if (!empty($data['posterFileUid'])) {
            $this->storePendingPoster($recordKey, (int)$data['posterFileUid']);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function peek(string $recordKey): ?array
    {
        $session = $this->getSessionData();
        if (!isset($session[$recordKey]) || !is_array($session[$recordKey])) {
            return null;
        }

        return $session[$recordKey];
    }

    public function resolvePosterFileUidForSave(string $recordKey): int
    {
        $posterUid = $this->pullPendingPoster($recordKey);
        if ($posterUid > 0) {
            return $posterUid;
        }

        $pending = $this->peek($recordKey);
        if (!is_array($pending) || empty($pending['posterFileUid'])) {
            return 0;
        }

        $posterUid = (int)$pending['posterFileUid'];
        unset($pending['posterFileUid']);
        $session = $this->getSessionData();
        if ($pending === []) {
            unset($session[$recordKey]);
        } else {
            $session[$recordKey] = $pending;
        }
        $this->getBackendUser()->setSessionData(self::SESSION_KEY, $session);

        return $posterUid;
    }

    public function storePendingPoster(string $recordKey, int $posterFileUid): void
    {
        if ($posterFileUid <= 0) {
            return;
        }

        $session = $this->getPosterSessionData();
        $session[$recordKey] = $posterFileUid;
        $this->getBackendUser()->setSessionData(self::POSTER_SESSION_KEY, $session);
    }

    public function pullPendingPoster(string $recordKey): int
    {
        $session = $this->getPosterSessionData();
        if (!isset($session[$recordKey])) {
            return 0;
        }

        $posterFileUid = (int)$session[$recordKey];
        unset($session[$recordKey]);
        $this->getBackendUser()->setSessionData(self::POSTER_SESSION_KEY, $session);

        return $posterFileUid;
    }

    public function buildRecordKey(string $tableName, string|int $uid): string
    {
        return $tableName . ':' . (string)$uid;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getSessionData(): array
    {
        $data = $this->getBackendUser()->getSessionData(self::SESSION_KEY);
        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, int>
     */
    private function getPosterSessionData(): array
    {
        $data = $this->getBackendUser()->getSessionData(self::POSTER_SESSION_KEY);
        return is_array($data) ? $data : [];
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
