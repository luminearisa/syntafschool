<?php

namespace App\Modules\Procurement\Infrastructure\Providers;

use App\Modules\Procurement\Domain\Contracts\NotificationProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Implementasi bawaan notifikasi: menulis ke log kanal `procurement`.
 *
 * Dipilih sebagai default agar tidak bergantung pada satu kanal; sekolah dapat
 * menggantinya dengan adapter email/WhatsApp/in-app tanpa menyentuh domain.
 */
class LogNotificationProvider implements NotificationProviderInterface
{
    public function notify(array $payload): void
    {
        Log::channel(config('logging.default'))->info('[procurement] notifikasi', [
            'event' => $payload['event'] ?? null,
            'title' => $payload['title'] ?? null,
            'recipients' => $payload['recipients'] ?? [],
            'channel' => $payload['channel'] ?? 'in-app',
        ]);
    }
}
