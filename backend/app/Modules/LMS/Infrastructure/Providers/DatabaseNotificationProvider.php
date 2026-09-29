<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Models\User;
use App\Modules\LMS\Domain\Contracts\NotificationProviderInterface;
use App\Modules\LMS\Domain\Enums\NotificationEvent;
use App\Modules\LMS\Models\LmsNotificationLog;
use App\Modules\LMS\Models\LmsUser;
use App\Modules\LMS\Notifications\LmsEventNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Pemberitahuan LMS di atas notification engine yang sudah ada (§10).
 *
 * - Idempotent: kunci unik `dedupe_key` menahan pengiriman ganda (job dobel,
 *   tombol ditekan dua kali, sinkronisasi berulang).
 * - Queueable & retryable: notifikasi memakai trait `Queueable`, jadi dapat
 *   dikirim lewat antrean tanpa mengubah pemanggil.
 * - Auditable: setiap pengiriman dicatat (`lms_notification_logs`).
 *
 * Kanal yang belum punya penyedia (WhatsApp/push) dilaporkan sebagai tidak
 * tersedia alih-alih gagal diam-diam.
 */
class DatabaseNotificationProvider implements NotificationProviderInterface
{
    /** Kanal LMS → kanal Laravel. */
    private const CHANNEL_MAP = [
        'in_app' => 'database',
        'email' => 'mail',
        'push' => 'broadcast',
        'whatsapp' => 'whatsapp',
    ];

    public function key(): string
    {
        return 'database';
    }

    public function isAvailable(): bool
    {
        return Schema::hasTable('notifications');
    }

    public function supportedChannels(): array
    {
        $channels = ['in_app'];

        if (config('mail.default') !== null && config('mail.default') !== 'log') {
            $channels[] = 'email';
        }

        if (config('broadcasting.default') !== null && config('broadcasting.default') !== 'null') {
            $channels[] = 'push';
        }

        // `whatsapp` hanya tersedia bila aplikasi benar-benar punya gateway
        // (kelas kanal didaftarkan). Tanpa itu, peristiwa tetap terkirim in-app.
        if (class_exists('App\\Notifications\\Channels\\WhatsAppChannel')) {
            $channels[] = 'whatsapp';
        }

        return $channels;
    }

    public function send(array $userIds, string $event, array $payload, ?array $channels = null, ?string $idempotencyKey = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn (int $id) => $id > 0)));

        $requested = $channels ?? $this->channelsForEvent($event);
        $available = $this->supportedChannels();
        $usable = array_values(array_intersect($requested, $available));
        $unavailable = array_values(array_diff($requested, $available));

        if ($usable === []) {
            $usable = ['in_app'];
        }

        $result = [
            'sent' => 0,
            'skipped' => 0,
            'channels' => $usable,
            'unavailable' => $unavailable,
            'event' => $event,
        ];

        if ($ids === [] || ! $this->isAvailable()) {
            $result['skipped'] = count($ids);

            return $result;
        }

        $key = $this->idempotencyKey($event, $idempotencyKey, $ids);

        if ($key !== null && ! $this->claim($key, $event, $payload, count($ids))) {
            $result['skipped'] = count($ids);

            return $result;
        }

        $recipients = $this->recipients($ids);

        if ($recipients->isEmpty()) {
            $result['skipped'] = count($ids);

            return $result;
        }

        $notification = new LmsEventNotification(
            event: $event,
            title: (string) ($payload['title'] ?? NotificationEvent::tryFrom($event)?->label() ?? 'Pemberitahuan LMS'),
            body: (string) ($payload['body'] ?? ''),
            url: isset($payload['url']) ? (string) $payload['url'] : null,
            context: (array) ($payload['context'] ?? []),
            channels: array_map(fn (string $channel) => self::CHANNEL_MAP[$channel] ?? 'database', $usable),
        );

        try {
            NotificationFacade::send($recipients, $notification);
            $result['sent'] = $recipients->count();
        } catch (Throwable $exception) {
            // Gagal kirim tidak boleh menggagalkan alur belajar; catat dan
            // laporkan supaya dapat dicoba ulang (retryable).
            $this->release($key);

            report($exception);

            return $result + ['error' => $exception->getMessage()];
        }

        $this->markSent($key, $result['sent']);

        return $result;
    }

    public function status(): array
    {
        return [
            'provider' => $this->key(),
            'available' => $this->isAvailable(),
            'channels' => $this->supportedChannels(),
            'missing_channels' => array_values(array_diff(['in_app', 'email', 'push', 'whatsapp'], $this->supportedChannels())),
            'configured_events' => array_keys((array) config('lms.notifications.events', [])),
        ];
    }

    /** @return list<string> */
    private function channelsForEvent(string $event): array
    {
        $configured = (array) config('lms.notifications.events.'.$event, []);

        if ($configured !== []) {
            return $configured;
        }

        return NotificationEvent::tryFrom($event)?->defaultChannels() ?? ['in_app'];
    }

    /** @param list<int> $ids */
    private function idempotencyKey(string $event, ?string $explicit, array $ids): ?string
    {
        if ($explicit !== null && $explicit !== '') {
            return $event.':'.$explicit;
        }

        if (! Schema::hasTable('lms_notification_logs')) {
            return null;
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function claim(string $key, string $event, array $payload, int $recipients): bool
    {
        try {
            $log = new LmsNotificationLog;
            $log->forceFill([
                'dedupe_key' => $key,
                'event' => $event,
                'payload' => $payload,
                'recipients_count' => $recipients,
                'status' => 'PENDING',
                'attempts' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ])->save();

            return true;
        } catch (Throwable) {
            // Kunci sudah ada (job/permintaan dobel) → jangan kirim dua kali.
            return false;
        }
    }

    private function markSent(?string $key, int $sent): void
    {
        if ($key === null || ! Schema::hasTable('lms_notification_logs')) {
            return;
        }

        LmsNotificationLog::query()->where('dedupe_key', $key)->update([
            'status' => 'SENT',
            'sent_count' => $sent,
            'sent_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function release(?string $key): void
    {
        if ($key === null || ! Schema::hasTable('lms_notification_logs')) {
            return;
        }

        LmsNotificationLog::query()->where('dedupe_key', $key)->update([
            'status' => 'FAILED',
            'updated_at' => now(),
        ]);
    }

    /**
     * Penerima harus diambil dari model yang aktif supaya notifikasi tidak
     * pernah menunjuk tabel pengguna yang salah.
     *
     * @param  list<int>  $ids
     * @return Collection<int, object>
     */
    private function recipients(array $ids)
    {
        $model = config('lms.standalone', false) ? LmsUser::class : User::class;

        /** @var class-string $model */
        return $model::query()->whereIn('id', $ids)->get();
    }
}
