<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Models\AttendanceStatus;
use App\Models\StudentAttendance;
use App\Modules\LMS\Domain\Contracts\AttendanceProviderInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Integrated Mode: kehadiran akademik dibaca dari `student_attendances`
 * (status berkode `H`/`A`/`I`/`S`/`T` pada `attendance_statuses`).
 *
 * Hanya membaca — LMS tidak pernah menulisi kehadiran akademik (§9). Rekap di
 * sini adalah *academic attendance*; kehadiran belajar LMS dan kehadiran kelas
 * langsung dihitung modulnya masing-masing.
 */
class ErpAttendanceProvider implements AttendanceProviderInterface
{
    /** Kode status akademik → kunci bucket. */
    private const BUCKETS = [
        AttendanceStatus::CODE_PRESENT => 'present',
        AttendanceStatus::CODE_LATE => 'late',
        AttendanceStatus::CODE_ABSENT => 'absent',
        AttendanceStatus::CODE_PERMITTED => 'excused',
        AttendanceStatus::CODE_SICK => 'excused',
    ];

    public function key(): string
    {
        return 'erp';
    }

    public function isAvailable(): bool
    {
        return Schema::hasTable('student_attendances') && Schema::hasTable('attendance_statuses');
    }

    public function academicSummary(int $studentId, ?string $from = null, ?string $to = null): array
    {
        $rows = $this->records($studentId, $from, $to);

        $byCode = [];
        $byBucket = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];

        foreach ($rows as $row) {
            $code = (string) ($row->code ?? '');
            $byCode[$code] = ($byCode[$code] ?? 0) + 1;
            $bucket = self::BUCKETS[$code] ?? null;

            if ($bucket !== null) {
                $byBucket[$bucket]++;
            }
        }

        $total = $rows->count();

        return [
            'records' => $total,
            'present' => $byBucket['present'],
            'absent' => $byBucket['absent'],
            'late' => $byBucket['late'],
            'excused' => $byBucket['excused'],
            'rate' => $total === 0
                ? null
                : round(($byBucket['present'] + $byBucket['late']) / $total * 100, 2),
            'by_status' => $byCode,
            'source' => 'erp',
        ];
    }

    public function academicRecords(int $studentId, ?string $from = null, ?string $to = null, int $limit = 30): array
    {
        return $this->records($studentId, $from, $to, $limit)
            ->map(fn (StudentAttendance $row) => [
                'date' => $row->date?->toDateString(),
                'status' => (string) ($row->code ?? ''),
                'label' => (string) ($row->status_name ?? ''),
                'subject' => $row->subject?->name,
                'note' => $row->notes,
            ])
            ->all();
    }

    public function isBoardingStudent(int $studentId): bool
    {
        if (! Schema::hasTable('boarding_placements')) {
            return false;
        }

        return DB::table('boarding_placements')
            ->where('student_id', $studentId)
            ->when(
                Schema::hasColumn('boarding_placements', 'is_active'),
                fn ($query) => $query->where('is_active', true),
            )
            ->exists();
    }

    /**
     * Baris presensi + kode status (join sekali, tanpa N+1).
     *
     * @return Collection<int, StudentAttendance>
     */
    private function records(int $studentId, ?string $from, ?string $to, int $limit = 0): Collection
    {
        if (! $this->isAvailable()) {
            return new Collection;
        }

        return StudentAttendance::query()
            ->select('student_attendances.*', 'attendance_statuses.code as code', 'attendance_statuses.name as status_name')
            ->leftJoin('attendance_statuses', 'attendance_statuses.id', '=', 'student_attendances.attendance_status_id')
            ->with(['subject:id,name'])
            ->where('student_attendances.student_id', $studentId)
            ->when($from !== null, fn ($query) => $query->whereDate('student_attendances.date', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('student_attendances.date', '<=', $to))
            ->orderByDesc('student_attendances.date')
            ->when($limit > 0, fn ($query) => $query->limit($limit))
            ->get();
    }
}
