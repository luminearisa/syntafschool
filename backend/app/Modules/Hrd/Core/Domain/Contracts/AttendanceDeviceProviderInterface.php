<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Titik integrasi ke luar modul HRD: Perangkat presensi.
 *
 * Bila modul tujuan belum terpasang, implementasi bawaan mengembalikan jawaban
 * "belum tersedia" dan peristiwanya tetap tercatat di outbox — bukan membuat
 * modul HRD gagal. Pola ini sama dengan Phase 1 dan menjaga batas modul.
 */
interface AttendanceDeviceProviderInterface
{
    /**
     * Kode penyedia (manual, qr, nfc, rfid, fingerprint, face, device_api).
     */
    public function code(): string;
    /**
     * Menyeragamkan muatan mentah perangkat menjadi peristiwa presensi.
     *
     * @return array<string, mixed>
     */
    public function normalize(array $raw): array;
    /**
     * Apakah perangkat dapat menyimpan lalu menyinkronkan di luar jaringan.
     */
    public function supportsOffline(): bool;
}
