<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\NotificationGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Notifikasi (§64, §86): pengingat shalat, iqamah, kajian, Jumat,
 * tugas, dan perubahan jadwal.
 */
class DatabaseNotificationGateway implements NotificationGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\\Modules\\Activity\\Domain\\Contracts\\NotificationProviderInterface')
            ?? $this->delegate('App\\Modules\\LMS\\Domain\\Contracts\\NotificationProviderInterface')
            ?? $this->delegate('App\\Modules\\Bk\\Domain\\Contracts\\NotificationProviderInterface');
    }

    public function send(array $payload): array
    {
        if ($provider = $this->provider()) {
            try {
                $recipientIds = (array) ($payload['user_ids'] ?? []);
                $body = $payload;
                unset($body['user_ids']);

                return ['sent' => true, 'result' => $provider->send($recipientIds, $body)];
            } catch (\Throwable $e) {
                return ['sent' => false, 'error' => $e->getMessage()];
            }
        }

        return $this->queue($payload);
    }

    public function sendToRoles(array $roles, array $payload): array
    {
        $userIds = $this->usersWithRoles($roles);

        return $this->send(array_merge($payload, ['user_ids' => $userIds]));
    }

    public function schedule(array $payload): array
    {
        return $this->queue($payload, 'SCHEDULED');
    }

    /** Antrean notifikasi internal modul (dikirim oleh scheduler institusi). */
    protected function queue(array $payload, string $status = 'QUEUED'): array
    {
        $table = $this->firstTable(['notifications', 'religious_notification_queue']);
        if ($table === null) {
            return ['sent' => false, 'reason' => $this->unavailableReason()];
        }

        $columns = $this->columns($table);
        $data = [];
        foreach ([
            'title' => $payload['title'] ?? null,
            'body' => $payload['body'] ?? ($payload['message'] ?? null),
            'message' => $payload['message'] ?? ($payload['body'] ?? null),
            'type' => $payload['type'] ?? 'RELIGIOUS',
            'schedule_at' => $payload['schedule_at'] ?? ($payload['send_at'] ?? null),
            'status' => $status,
            'payload' => json_encode($payload),
            'created_at' => now(),
            'updated_at' => now(),
        ] as $key => $value) {
            if (in_array($key, $columns, true) && $value !== null) {
                $data[$key] = $value;
            }
        }

        if ($data === []) {
            return ['sent' => false, 'reason' => 'Tabel notifikasi tidak memiliki kolom yang dapat dipetakan.'];
        }

        $id = DB::table($table)->insertGetId($data);

        return ['sent' => true, 'queued' => true, 'id' => $id, 'source' => $table];
    }

    protected function usersWithRoles(array $roles): array
    {
        return $this->safeRoles($roles);
    }

    protected function safeRoles(array $roles): array
    {
        try {
            if (! class_exists('Spatie\\Permission\\Models\\Role')) {
                return [];
            }
            $roleModel = 'Spatie\\Permission\\Models\\Role';

            return $roleModel::query()->whereIn('name', $roles)
                ->with('users:id')->get()
                ->flatMap(fn ($role) => $role->users->pluck('id'))
                ->unique()->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function available(): bool
    {
        return $this->provider() !== null
            || $this->firstTable(['notifications', 'religious_notification_queue']) !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Sarana notifikasi tidak tersedia pada instalasi ini.';
    }
}
