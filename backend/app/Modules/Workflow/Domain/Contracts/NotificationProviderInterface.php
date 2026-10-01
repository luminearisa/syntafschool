<?php

namespace App\Modules\Workflow\Domain\Contracts;

use App\Modules\Workflow\Domain\Support\NotificationMessage;

/**
 * Pengiriman notifikasi workflow (§67).
 *
 * Implementasi default menulis ke `workflow_notifications` (log idempoten) dan
 * ke tabel `notifications` bawaan Laravel untuk lonceng in-app. Sekolah dapat
 * menggantinya dengan WhatsApp gateway atau SMTP provider tanpa menyentuh
 * engine: cukup ubah binding pada `WorkflowServiceProvider`.
 */
interface NotificationProviderInterface
{
    /** Apakah provider mampu mengirim kanal ini. */
    public function supports(string $channel): bool;

    /**
     * Kirim satu pesan. Implementasi **tidak** bertanggung jawab atas
     * idempotensi — itu urusan `WorkflowNotificationService` yang menulis
     * `idempotency_key` unik sebelum memanggil provider ini.
     *
     * @return bool true bila terkirim
     */
    public function send(NotificationMessage $message): bool;
}
