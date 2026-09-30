<?php

namespace App\Modules\Clinic\Infrastructure\Providers;

use App\Models\User;
use App\Modules\Clinic\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Clinic\Models\ClinicNotificationLog;
use App\Modules\Clinic\Notifications\ClinicNotification;
use Illuminate\Support\Facades\DB;

/**
 * Pengiriman pemberitahuan klinis lewat kanal notifikasi Laravel.
 *
 * Setiap percobaan dicatat di `clinic_notification_logs`, termasuk yang
 * **tidak dikirim** karena aturan privasi — supaya sekolah dapat membuktikan
 * apa yang sengaja ditahan dan alasannya. `dedupe_key` unik mencegah
 * pemberitahuan yang sama terkirim dua kali pada kunjungan yang sama.
 */
class DatabaseNotificationProvider implements NotificationProviderInterface
{
    public function send(array $recipientUserIds, array $payload): array
    {
        $recipients = array_values(array_unique(array_filter($recipientUserIds)));

        if ($recipients === []) {
            $this->log($payload, null, 'SUPPRESSED', 'Tidak ada penerima dengan akun aktif.');

            return ['sent' => 0, 'suppressed' => 1, 'channels' => []];
        }

        $dedupe = $payload['dedupe_key'] ?? null;
        if ($dedupe !== null && ClinicNotificationLog::query()->where('dedupe_key', $dedupe)->where('status', 'SENT')->exists()) {
            $this->log($payload, null, 'SUPPRESSED', 'Pemberitahuan identik sudah terkirim.');

            return ['sent' => 0, 'suppressed' => 1, 'channels' => []];
        }

        $channels = $this->channelSelection();
        $notification = new ClinicNotification([
            'type' => 'clinic.'.$payload['event'],
            'title' => $payload['title'],
            'body' => $payload['body'],
            'url' => $payload['url'] ?? null,
            'event' => $payload['event'],
            'patient_id' => $payload['patient_id'] ?? null,
            'visit_id' => $payload['visit_id'] ?? null,
        ], $channels);

        $sent = 0;
        $failed = 0;

        $users = User::query()->whereIn('id', $recipients)->where('is_active', true)->get();

        foreach ($users as $user) {
            try {
                // Notifikasi dikirim langsung; modul ini tidak memaksa antrean
                // agar perilaku tetap dapat diuji tanpa worker queue.
                $user->notifyNow($notification);
                $sent++;
                $this->log($payload, (int) $user->id, 'SENT', null, $channels[0] ?? 'DATABASE');
            } catch (\Throwable $e) {
                $failed++;
                $this->log($payload, (int) $user->id, 'FAILED', $e->getMessage());
            }
        }

        return [
            'sent' => $sent,
            'suppressed' => (int) ($recipients === [] ? 1 : 0) + $failed * 0,
            'channels' => $channels,
        ];
    }

    public function availableChannels(): array
    {
        return $this->channelSelection();
    }

    /** @return list<string> */
    private function channelSelection(): array
    {
        $channels = ['database'];

        // Email hanya dipakai bila instalasi memang mengonfigurasi mailer.
        if (config('mail.default') && config('mail.default') !== 'log' && (bool) config('clinic.privacy.parent_visible_medication', true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /** @param array<string, mixed> $payload */
    private function log(array $payload, ?int $recipientId, string $status, ?string $reason, string $channel = 'DATABASE'): void
    {
        DB::table('clinic_notification_logs')->insert([
            'event' => $payload['event'],
            'patient_id' => $payload['patient_id'] ?? null,
            'visit_id' => $payload['visit_id'] ?? null,
            'recipient_id' => $recipientId,
            'channel' => strtoupper($channel),
            'audience' => $payload['audience'] ?? 'PARENT',
            'title' => mb_substr((string) $payload['title'], 0, 190),
            'body' => $payload['body'] ?? null,
            'status' => $status,
            'suppressed_reason' => $reason,
            // dedupe hanya untuk baris terkirim agar percobaan gagal bisa diulang
            'dedupe_key' => $status === 'SENT'
                ? ($payload['dedupe_key'] ?? null)
                : null,
            'sent_at' => $status === 'SENT' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
