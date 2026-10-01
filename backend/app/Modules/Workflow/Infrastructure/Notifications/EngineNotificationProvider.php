<?php

namespace App\Modules\Workflow\Infrastructure\Notifications;

use App\Modules\Workflow\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Workflow\Domain\Support\NotificationMessage;
use App\Modules\Workflow\Notifications\WorkflowAlertNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Provider notifikasi bawaan.
 *
 *  - `IN_APP`  → ditulis ke tabel `notifications` bawaan Laravel (lonceng aplikasi).
 *  - `EMAIL`   → lewat `mail` channel pada notifikasi yang sama.
 *  - `PUSH`/`WHATSAPP` → belum ada gateway; provider menolaknya secara jujur
 *    (baris notifikasi berstatus FAILED dengan alasan tertulis) alih-alih
 *    mengaku berhasil. Sekolah yang punya gateway cukup mengganti binding
 *    `NotificationProviderInterface`.
 *
 * Pesan tidak pernah memuat isi bisnis sensitif: hanya kode request, nama
 * langkah, dan tautan. Isi dokumen tetap berada di modul pemiliknya.
 */
final class EngineNotificationProvider implements NotificationProviderInterface
{
    public function supports(string $channel): bool
    {
        return in_array(strtoupper($channel), ['IN_APP', 'EMAIL'], true);
    }

    public function send(NotificationMessage $message): bool
    {
        $channel = strtoupper($message->channel);
        $recipient = $message->recipient;

        if ($recipient === null) {
            return false;
        }

        if (! $this->supports($channel)) {
            return false;
        }

        $channels = $channel === 'EMAIL' ? ['database', 'mail'] : ['database'];

        Notification::sendNow($recipient, new WorkflowAlertNotification(
            title: $message->title,
            body: $message->body,
            payload: $message->payload,
            event: $message->event,
            actionUrl: $message->actionUrl,
        ), $channels);

        Log::info('workflow.notification.sent', [
            'event' => $message->event,
            'channel' => $channel,
            'recipient' => $recipient->id,
        ]);

        return true;
    }
}
