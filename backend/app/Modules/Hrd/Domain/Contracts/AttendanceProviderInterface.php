<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak presensi & cuti (§66, fondasi Leave/Absence).
 *
 * HRD menjadi sumber kebenaran *siapa* pegawainya; modul Presensi menjadi sumber
 * kebenaran *kapan* ia hadir. Kontrak ini menjaga pembagian itu: HRD tidak
 * menghitung jam kehadiran, tetapi menanyakan rekap ketika menyusun laporan
 * kepegawaian — dan menutup akses presensi ketika pegawai sudah keluar.
 */
interface AttendanceProviderInterface
{
    /**
     * Rekap kehadiran pegawai pada rentang tanggal.
     *
     * @return array<string, mixed>
     */
    public function summaryFor(int $employeeId, string $fromDate, string $toDate): array;

    /**
     * Nonaktifkan partisipasi pegawai (mis. setelah tanggal berakhir kerja).
     */
    public function deactivateEmployee(int $employeeId, string $fromDate): bool;

    /**
     * Cuti yang sedang berjalan pada tanggal tertentu, bila modul cuti terpasang.
     *
     * @return list<array<string, mixed>>
     */
    public function onLeaveOn(string $date): array;

    public function isAvailable(): bool;
}
