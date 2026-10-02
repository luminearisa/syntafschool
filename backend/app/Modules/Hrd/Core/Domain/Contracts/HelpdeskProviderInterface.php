<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Titik integrasi ke luar modul HRD: Helpdesk ERP.
 *
 * Bila modul tujuan belum terpasang, implementasi bawaan mengembalikan jawaban
 * "belum tersedia" dan peristiwanya tetap tercatat di outbox — bukan membuat
 * modul HRD gagal. Pola ini sama dengan Phase 1 dan menjaga batas modul.
 */
interface HelpdeskProviderInterface
{
    /**
     * Membuat tiket pada Helpdesk ERP bila tersedia.
     *
     * @return array<string, mixed>
     */
    public function open(array $ticket, int $actorUserId): array;
    /**
     * Menambah tanggapan.
     *
     * @return array<string, mixed>
     */
    public function comment(string $ticketReference, string $body, int $actorUserId): array;
    /**
     * Menutup tiket.
     *
     * @return array<string, mixed>
     */
    public function close(string $ticketReference, int $actorUserId, ?string $resolution = null): array;
}
