<?php

namespace App\Modules\Clinic\Domain\Contracts;

/**
 * Pengiriman pemberitahuan klinis.
 *
 * Isi pesan sudah disaring kebijakan privasi sebelum sampai ke kontrak ini,
 * sehingga kanal tidak pernah menerima catatan klinis sensitif yang tidak
 * seharusnya keluar dari klinik.
 */
interface NotificationProviderInterface
{
    /**
     * @param list<int> $recipientUserIds
     * @param array{event: string, title: string, body: string, url?: ?string, audience: string, patient_id?: ?int, visit_id?: ?int, dedupe_key?: ?string} $payload
     * @return array{sent: int, suppressed: int, channels: list<string>}
     */
    public function send(array $recipientUserIds, array $payload): array;

    /** Kanal yang aktif di instalasi ini (database, mail, dsb). */
    public function availableChannels(): array;
}
