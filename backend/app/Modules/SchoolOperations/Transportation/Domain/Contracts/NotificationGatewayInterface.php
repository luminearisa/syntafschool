<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/** Notifikasi (in-app/push/WhatsApp/email/SMS) melalui penyedia yang ada (§39). */
interface NotificationGatewayInterface
{
    public function send(array $recipientUserIds, array $payload): array;

    public function available(): bool;
}
