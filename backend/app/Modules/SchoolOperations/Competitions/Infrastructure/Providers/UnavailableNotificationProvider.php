<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Infrastructure\Providers;

use App\Modules\SchoolOperations\Competitions\Domain\Contracts\NotificationProviderInterface;

/** Safe no-op; notifications remain queued for delivery when provider is absent. */
class UnavailableNotificationProvider implements NotificationProviderInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function unavailableReason(): ?string
    {
        return 'Notification provider belum dikonfigurasi.';
    }

    public function send(array $recipientUserIds, array $payload): array
    {
        return ['sent' => 0, 'suppressed' => count($recipientUserIds), 'reason' => $this->unavailableReason()];
    }
}
