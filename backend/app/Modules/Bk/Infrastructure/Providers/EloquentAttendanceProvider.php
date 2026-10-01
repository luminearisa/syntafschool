<?php

namespace App\Modules\Bk\Infrastructure\Providers;

use App\Modules\Bk\Domain\Contracts\AttendanceProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan kehadiran dari modul presensi.
 *
 * Kode status dibaca dari tabel `attendance_statuses` (mis. HADIR, SAKIT, IZIN,
 * ALPA) sehingga sekolah tetap bebas menamai statusnya; yang dipetakan hanya
 * kelompoknya.
 */
class EloquentAttendanceProvider implements AttendanceProviderInterface
{
    public function summary(int $studentId, string $from, string $to): array
    {
        $rows = DB::table('student_attendances')
            ->leftJoin('attendance_statuses', 'attendance_statuses.id', '=', 'student_attendances.attendance_status_id')
            ->where('student_attendances.student_id', $studentId)
            ->whereBetween('student_attendances.date', [$from, $to])
            ->get(['attendance_statuses.code as code', 'attendance_statuses.name as name']);

        $bucket = ['present' => 0, 'late' => 0, 'sick' => 0, 'permitted' => 0, 'absent' => 0];

        foreach ($rows as $row) {
            $key = $this->bucketFor((string) ($row->code ?? $row->name ?? ''));
            $bucket[$key]++;
        }

        $total = $rows->count();
        $attended = $bucket['present'] + $bucket['late'];

        return [
            'present' => $bucket['present'],
            'late' => $bucket['late'],
            'sick' => $bucket['sick'],
            'permitted' => $bucket['permitted'],
            'absent' => $bucket['absent'],
            'total' => $total,
            'percentage' => $total === 0 ? null : round($attended / $total * 100, 2),
        ];
    }

    /** Kelompok status: hadir/terlambat/sakit/izin/alpa. */
    private function bucketFor(string $code): string
    {
        $normalized = strtoupper(trim($code));

        return match (true) {
            str_contains($normalized, 'HADIR'), $normalized === 'PRESENT', $normalized === 'H' => 'present',
            str_contains($normalized, 'LAMBAT'), $normalized === 'LATE' => 'late',
            str_contains($normalized, 'SAKIT'), $normalized === 'SICK' => 'sick',
            str_contains($normalized, 'IZIN'), $normalized === 'PERMITTED', $normalized === 'EXCUSED' => 'permitted',
            default => 'absent',
        };
    }
}
