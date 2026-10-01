<?php

namespace App\Modules\Asset\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\NotificationProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notifikasi bawaan: ditulis ke tabel `notifications` milik Laravel (kanal basis
 * data) sehingga muncul pada lonceng notifikasi aplikasi.
 *
 * Bila tabelnya belum ada, modul tetap berjalan: baris notifikasi internal
 * (`asset_notifications`) sudah dicatat lebih dulu oleh layanan, jadi tidak ada
 * pengingat yang hilang tanpa jejak.
 */
class DatabaseNotificationProvider implements NotificationProviderInterface
{
    public function notify(array $userIds, array $roles, string $event, array $payload): void
    {
        $this->notifyMany(['users' => $userIds, 'roles' => $roles], $event, $payload);
    }

    public function notifyMany(array $recipients, string $event, array $payload): int
    {
        if (! Schema::hasTable('notifications')) {
            return 0;
        }

        $userIds = $this->resolveRecipients($recipients);

        if ($userIds === []) {
            return 0;
        }

        $rows = [];

        foreach ($userIds as $userId) {
            $rows[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => 'App\\Modules\\Asset\\Notifications\\AssetDatabaseNotification',
                'notifiable_type' => \App\Models\User::class,
                'notifiable_id' => $userId,
                'data' => json_encode(['event' => $event] + $payload),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('notifications')->insert($rows);

        return count($rows);
    }

    protected function resolveRecipients(array $recipients): array
    {
        if (array_key_exists('users', $recipients) || array_key_exists('roles', $recipients)) {
            $ids = array_map('intval', (array) ($recipients['users'] ?? []));

            foreach ((array) ($recipients['roles'] ?? []) as $role) {
                $ids = array_merge($ids, $this->usersWithRole((string) $role));
            }

            return array_values(array_unique(array_filter($ids)));
        }

        return array_map('intval', $recipients);
    }

    /** @return list<int> */
    protected function usersWithRole(string $role): array
    {
        if (! Schema::hasTable('model_has_roles') || ! Schema::hasTable('roles')) {
            return [];
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', $role)
            ->where('model_has_roles.model_type', \App\Models\User::class)
            ->pluck('model_has_roles.model_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
