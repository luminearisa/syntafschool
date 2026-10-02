<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Notifikasi (modul Notification).
 *
 * Meneruskan ke kontrak Notification; modul notifikasi tetap sumber kebenarannya (§64).
 */
class ActivityNotificationProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke kontrak Notification; modul notifikasi tetap sumber kebenarannya (§64).';

    protected const TARGET_CONTRACT = 'App\\Modules\\Activity\\Domain\\Contracts\\NotificationProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function send(array $recipientUserIds, array $payload): array
    {
        $value = $this->call('send', [$recipientUserIds, $payload]);

        return $this->wrap($value);
    }
}
