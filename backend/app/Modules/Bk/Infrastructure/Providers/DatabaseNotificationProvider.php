<?php

namespace App\Modules\Bk\Infrastructure\Providers;

use App\Models\User;
use App\Modules\Bk\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Bk\Models\BkNotificationLog;
use Illuminate\Support\Facades\DB;

/**
 * Notifikasi BK lewat kanal notifikasi Laravel (tabel `notifications`).
 *
 * Aturan yang ditegakkan di sini: yang dikirim hanyalah **ringkasan aman** yang
 * sudah disiapkan pemanggil. Provider ini tidak pernah menerima maupun menulis
 * isi catatan konseling, sehingga kebocoran lewat notifikasi tidak mungkin
 * terjadi karena kesalahan pemanggil.
 */
class DatabaseNotificationProvider implements NotificationProviderInterface
{
    public function send(int $userId, string $event, string $summary, array $payload = []): array
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            BkNotificationLog::query()->create([
                'event' => $event,
                'notifiable_user_id' => null,
                'status' => 'SKIPPED',
                'summary' => $summary,
                'payload' => $payload,
                'channel' => 'database',
                'error' => 'Pengguna tidak ditemukan.',
            ]);

            return ['status' => 'SKIPPED', 'channel' => 'database'];
        }

        DB::table('notifications')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'bk.event',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => json_encode([
                'event' => $event,
                'summary' => $summary,
                'payload' => $payload,
            ], JSON_UNESCAPED_UNICODE),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        BkNotificationLog::query()->create([
            'event' => $event,
            'notifiable_user_id' => $user->id,
            'channel' => 'database',
            'case_id' => $payload['case_id'] ?? null,
            'student_id' => $payload['student_id'] ?? null,
            'status' => 'SENT',
            'summary' => $summary,
            'payload' => $payload,
            'sent_at' => now(),
        ]);

        return ['status' => 'SENT', 'channel' => 'database'];
    }

    public function canReach(int $userId): bool
    {
        return User::query()->whereKey($userId)->where('is_active', true)->exists();
    }
}
