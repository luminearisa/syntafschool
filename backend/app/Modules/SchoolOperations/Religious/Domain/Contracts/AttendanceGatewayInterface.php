<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Attendance Provider bersama (§22, §39). Modul ini BUKAN mesin presensi baru.
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface AttendanceGatewayInterface
{
    /** Catat satu presensi ke mesin presensi institusi. */
    public function record(array $payload): array;

    /** Catat banyak presensi sekaligus. */
    public function bulkRecord(array $payloads): array;

    /** Daftar perangkat presensi (QR/RFID/NFC/kartu). */
    public function devices(array $filters = []): array;

    /** Metode presensi yang didukung instalasi ini. */
    public function capabilities(): array;

    /** Ketersediaan mesin presensi. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
