<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Domain\Contracts;

/** Notifications are delivered by the shared Notification domain. */
interface NotificationProviderInterface extends ProviderAvailabilityInterface
{
    /** @param list<int> $recipientUserIds @return array<string, mixed> */
    public function send(array $recipientUserIds, array $payload): array;
}
