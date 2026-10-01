<?php

namespace App\Modules\Asset\Application\Services;

use App\Modules\Asset\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Asset\Models\AssetNotification;
use Illuminate\Database\Eloquent\Model;

/**
 * Notifikasi operasional modul (tugas, pengingat, peringatan).
 *
 * Baris notifikasi SELALU dicatat lebih dulu, baru dikirim lewat kontrak. Bila
 * penyedia notifikasi belum terpasang, catatannya tetap ada — sehingga pengingat
 * yang seharusnya terkirim dapat diperiksa, bukan menghilang tanpa jejak.
 */
class AssetNotificationService
{
    public function __construct(private readonly NotificationProviderInterface $provider) {}

    /** @param list<int> $userIds @param list<string> $roles */
    public function notify(array $userIds, array $roles, string $event, string $title, ?string $body = null, ?Model $subject = null, array $meta = []): int
    {
        $sent = 0;

        // Penerima kosong (mis. pengelola fasilitas belum diisi) dilewati: menulis
        // recipient_id 0 bukan "tidak ada penerima", melainkan pelanggaran kunci asing.
        $userIds = array_values(array_filter($userIds, fn ($id) => is_numeric($id) && (int) $id > 0));

        foreach (array_unique($userIds) as $userId) {
            AssetNotification::query()->create([
                'event' => $event,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'recipient_id' => $userId,
                'title' => $title,
                'body' => $body,
                'channel' => 'database',
                'status' => 'SENT',
                'sent_at' => now(),
                'meta' => $meta,
            ]);

            $sent++;
        }

        foreach (array_unique($roles) as $role) {
            AssetNotification::query()->create([
                'event' => $event,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'recipient_role' => $role,
                'title' => $title,
                'body' => $body,
                'channel' => 'database',
                'status' => 'SENT',
                'sent_at' => now(),
                'meta' => $meta,
            ]);

            $sent++;
        }

        if ($sent > 0) {
            $this->provider->notify($userIds, $roles, $event, $meta + ['title' => $title, 'body' => $body]);
        }

        return $sent;
    }
}
