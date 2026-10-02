<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\NotificationGatewayInterface;
use Illuminate\Support\Facades\DB;

class DatabaseNotificationGateway implements NotificationGatewayInterface
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
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => $payload['type'] ?? 'transportation',
                'notifiable_type' => 'App\Models\User',
                'notifiable_id' => $userId,
                'data' => json_encode([
                    'title' => $payload['title'] ?? 'Transportasi',
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
