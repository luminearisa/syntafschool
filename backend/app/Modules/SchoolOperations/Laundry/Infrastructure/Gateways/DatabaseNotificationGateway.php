<?php

namespace App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Laundry\Domain\Contracts\NotificationProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Notifikasi in-app (§45): delegasi ke provider modul lain bila terpasang,
 * fallback menulis langsung ke tabel `notifications` Laravel.
 */
class DatabaseNotificationGateway implements NotificationProviderInterface
{
    use GatewaySupport;

    public function send(array $recipientUserIds, array $payload): array
    {
        $recipientUserIds = array_values(array_unique(array_filter(array_map('intval', $recipientUserIds))));
        if ($recipientUserIds === []) {
            return ['sent' => 0, 'skipped' => true, 'reason' => 'Tidak ada penerima.'];
        }

        $provider = $this->delegate('App\Modules\Activity\Domain\Contracts\NotificationProviderInterface')
            ?? $this->delegate('App\Modules\Clinic\Domain\Contracts\NotificationProviderInterface');
        if ($provider) {
            try {
                $result = (array) $provider->send($recipientUserIds, $payload);

                return ['sent' => count($recipientUserIds), 'provider' => 'module', 'response' => $result];
            } catch (\Throwable) {
                // jatuh ke penyimpanan lokal
            }
        }

        if (! $this->has('notifications')) {
            return ['sent' => 0, 'skipped' => true, 'reason' => 'Tabel notifikasi tidak tersedia.'];
        }

        $now = now();
        $rows = [];
        foreach ($recipientUserIds as $userId) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'type' => $payload['type'] ?? 'laundry',
                'notifiable_type' => 'App\\Models\\User',
                'notifiable_id' => $userId,
                'data' => json_encode([
                    'title' => $payload['title'] ?? 'Laundry',
                    'body' => $payload['body'] ?? '',
                    'event' => $payload['event'] ?? null,
                    'payload' => $payload['payload'] ?? [],
                ]),
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('notifications')->insert($rows);

        return ['sent' => count($rows), 'provider' => 'database'];
    }

    public function available(): bool
    {
        return $this->has('notifications');
    }
}
