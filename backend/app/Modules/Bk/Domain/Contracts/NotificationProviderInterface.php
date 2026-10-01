<?php

namespace App\Modules\Bk\Domain\Contracts;

/**
 * Pengiriman notifikasi BK.

Pesan **tidak pernah** memuat isi catatan konseling: hanya peristiwa, nomor
kasus, dan ajakan tindakan.
 */
interface NotificationProviderInterface
{

    /**
     * Kirim notifikasi ringkas (tanpa isi catatan) dan catat hasilnya.
     *
     * @param  array<string, mixed>  $payload  metadata aman (nomor kasus, tanggal, dsb)
     * @return array{status: string, channel: string}
     */
    public function send(int $userId, string $event, string $summary, array $payload = []): array;

    /** Apakah pengguna ini punya kanal penerimaan yang aktif. */
    public function canReach(int $userId): bool;
}
