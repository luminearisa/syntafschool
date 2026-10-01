<?php

namespace App\Modules\Activity\Infrastructure\Providers;

use App\Models\User;
use App\Modules\Activity\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Activity\Models\ActivityNotificationLog;
use App\Modules\Activity\Notifications\ActivityNotification;

/**
 * Pengiriman notifikasi aktivitas (§67).
 *
 * Setiap percobaan dicatat — termasuk yang **ditahan** karena kunci idempoten
 * sudah pernah terkirim. Itu bukan pemborosan catatan: sekolah dapat membuktikan
 * bahwa tidak ada notifikasi ganda yang dikirim ke orang tua.
 */
class DatabaseNotificationProvider implements NotificationProviderInterface
{
    public function send(array $recipientUserIds, array $payload): array
    {
        $recipients = array_values(array_unique(array_filter(array_map('intval', $recipientUserIds))));
        $dedupe = (string) ($payload['dedupe_key'] ?? '');

        if ($dedupe !== '' && ActivityNotificationLog::query()->where('dedupe_key', $dedupe)->where('status', 'SENT')->exists()) {
            $this->log($payload, null, 'SUPPRESSED', 'Notifikasi identik sudah terkirim.', $dedupe);

            return ['sent' => 0, 'suppressed' => 1, 'channels' => []];
        }

        if ($recipients === []) {
            $this->log($payload, null, 'SUPPRESSED', 'Tidak ada penerima berakun.', $dedupe);

            return ['sent' => 0, 'suppressed' => 1, 'channels' => []];
        }

        $channels = (array) config('activity.notification.channels', ['database']);
        $sent = 0;

        foreach ($recipients as $userId) {
            $user = User::query()->find($userId);

            if ($user === null || ! $user->is_active) {
                $this->log($payload, $userId, 'SKIPPED', 'Akun tidak aktif.', $dedupe);

                continue;
            }

            try {
                $user->notify(new ActivityNotification($payload));
                $sent++;
                $this->log($payload, $userId, 'SENT', null, $dedupe);
            } catch (\Throwable $exception) {
                $this->log($payload, $userId, 'FAILED', $exception->getMessage(), $dedupe);
            }
        }

        return ['sent' => $sent, 'suppressed' => 0, 'channels' => $channels];
    }

    protected function log(array $payload, ?int $userId, string $status, ?string $error, string $dedupe): void
    {
        ActivityNotificationLog::query()->create([
            'event' => (string) ($payload['event'] ?? 'activity.unknown'),
            'program_id' => $payload['program_id'] ?? null,
            'student_id' => $payload['student_id'] ?? null,
            'recipient_user_id' => $userId,
            'channel' => implode(',', (array) config('activity.notification.channels', ['database'])),
            'status' => $status,
            'dedupe_key' => $dedupe === '' ? uniqid('activity-notification-', true) : $dedupe,
            'payload' => $payload,
            'error' => $error,
            'sent_at' => $status === 'SENT' ? now() : null,
        ]);
    }
}
