<?php

namespace App\Modules\Clinic\Domain\Contracts;

/**
 * Presensi siswa saat menjalani layanan klinis.
 *
 * Kunjungan ke UKS pada jam sekolah dapat memengaruhi presensi. Karena itu
 * kontrak ini **tidak pernah** menulis presensi kecuali kebijakan sekolah
 * mengizinkannya (`clinic.attendance.auto_mark`), dan hasilnya selalu
 * dikembalikan sebagai status agar Clinic dapat melaporkan apa yang terjadi.
 */
interface AttendanceProviderInterface
{
    public const MARKED = 'MARKED';
    public const ALREADY_PRESENT = 'ALREADY_PRESENT';
    public const SKIPPED_POLICY = 'SKIPPED_POLICY';
    public const SKIPPED_NOT_STUDENT = 'SKIPPED_NOT_STUDENT';
    public const FAILED = 'FAILED';

    /**
     * Tandai kunjungan klinis sebagai alasan presensi (mis. sakit/izin).
     *
     * @return array{status: string, attendance_id: ?int, message: string}
     */
    public function markMedicalVisit(int $studentId, string $date, string $reasonLabel, ?int $actorId): array;
}
