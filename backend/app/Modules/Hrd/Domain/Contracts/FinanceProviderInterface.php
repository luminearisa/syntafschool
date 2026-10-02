<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak keuangan (§66).
 *
 * HRD tidak menghitung gaji — itu fase berikutnya. Yang dibutuhkan sekarang:
 * menandai pegawai yang keluar untuk penyelesaian hak, dan mengetahui apakah
 * clearance keuangan sudah bersih sebelum pegawai dinyatakan `inactive`.
 */
interface FinanceProviderInterface
{
    /**
     * Minta pencatatan pegawai pada modul keuangan (mis. dimensi biaya pegawai).
     *
     * @param  array<string, mixed>  $payload
     */
    public function registerEmployee(int $employeeId, array $payload): bool;

    /**
     * Kewajiban keuangan pegawai yang belum selesai (piutang, potongan, uang muka).
     *
     * @return list<array<string, mixed>>
     */
    public function outstandingFor(int $employeeId): array;

    /**
     * Mulai proses clearance keuangan saat pegawai keluar.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function requestClearance(int $employeeId, array $payload): ?array;

    /** Apakah masih ada kewajiban keuangan yang menahan exit clearance? */
    public function hasOutstandingObligations(int $employeeId): bool;

    public function isAvailable(): bool;
}
