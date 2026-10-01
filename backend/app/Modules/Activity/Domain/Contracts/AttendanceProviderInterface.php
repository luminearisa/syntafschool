<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * Integrasi dengan modul Attendance yang sudah ada (§33).
 *
 * Modul Activity mencatat kehadiran **per sesi aktivitas** karena satu sesi
 * menghasilkan satu baris per siswa dengan makna yang khas aktivitas
 * (hadir/alpa/izin/terlambat). Bila sekolah ingin kehadiran aktivitas ikut
 * terlihat di modul Attendance, catatan dicerminkan lewat kontrak ini —
 * modul Activity tidak pernah menulis langsung ke tabel absensi akademik.
 */
interface AttendanceProviderInterface
{
    /** Cerminkan satu catatan kehadiran; kembalikan rujukan eksternal bila dibuat. */
    public function mirror(array $payload): ?string;

    /** Batalkan cerminan (mis. kehadiran dikoreksi). */
    public function revoke(string $reference): bool;

    /** Rekap kehadiran akademik siswa pada rentang tanggal — untuk aturan kelayakan. */
    public function summary(int $studentId, string $from, string $to): array;
}
