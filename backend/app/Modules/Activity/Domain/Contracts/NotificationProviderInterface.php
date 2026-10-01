<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * Notifikasi (§67). Penerapannya wajib idempoten: kunci `dedupe_key`
 * membuat peringatan yang sama tidak terkirim dua kali.
 */
interface NotificationProviderInterface
{
    /**
     * @param  list<int>  $recipientUserIds
     * @return array{sent: int, suppressed: int, channels: list<string>}
     */
    public function send(array $recipientUserIds, array $payload): array;
}
