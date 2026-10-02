<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Modules\Hrd\Domain\Contracts\NotificationProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notifikasi bawaan (§55): ditulis ke tabel `notifications` milik Laravel sehingga
 * muncul di lonceng notifikasi aplikasi.
 *
 * Kanal lain (WhatsApp/Push) ditambahkan dengan mengikat kontrak ini ke penyedia
 * lain — modul HRD tidak pernah memanggil penyedia tertentu secara langsung.
 */
class DatabaseNotificationProvider implements NotificationProviderInterface
{
    public function __construct(private readonly \App\Modules\Hrd\Domain\Contracts\UserAccountProviderInterface $users) {}

    public function notify(array $userIds, array $roles, string $event, array $payload): void
    {
        $this->notifyMany(['users' => $userIds, 'roles' => $roles], $event, $payload);
    }

    public function notifyMany(array $recipients, string $event, array $payload): int
    {
        $recipients = $this->resolve($recipients);

        if ($recipients === [] || ! Schema::hasTable('notifications')) {
            return 0;
        }

        $rows = [];

        foreach ($recipients as $userId) {
            $rows[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => 'App\\Modules\\Hrd\\Notifications\\HrdDatabaseNotification',
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

    public function availableChannels(): array
    {
        return ['database'];
    }

    /** @param array{users?: list<int>, roles?: list<string>}|list<int> $recipients */
    private function resolve(array $recipients): array
    {
        if (! array_key_exists('users', $recipients) && ! array_key_exists('roles', $recipients)) {
            return array_values(array_unique(array_map('intval', $recipients)));
        }

        $ids = array_map('intval', (array) ($recipients['users'] ?? []));

        foreach ((array) ($recipients['roles'] ?? []) as $role) {
            $ids = array_merge($ids, $this->users->usersWithRole((string) $role));
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
