<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use Illuminate\Support\Facades\DB;

/**
 * Analitik & laporan keagamaan (§71-§74).
 *
 * Batas etis yang ditegakkan modul (§71): seluruh angka di sini bersifat
 * DESKRIPTIF. Modul tidak menarik kesimpulan otomatis mengenai karakter atau
 * kualitas moral seseorang dari angka kehadiran.
 */
class ReligiousAnalyticsService
{
    public function __construct(protected ReligiousContext $context)
    {
    }

    /** Metrik utama (§71). */
    public function metrics(string $from, string $to): array
    {
        return [
            'period' => ['from' => $from, 'to' => $to],
            'prayer_attendance' => $this->attendanceMetrics($from, $to),
            'activity_participation' => $this->activityMetrics($from, $to),
            'mosque_utilization' => $this->utilizationMetrics($from, $to),
            'personnel' => app(PersonnelService::class)->dashboard(['from' => $from, 'to' => $to]),
            'ramadan' => $this->ramadanMetrics($from, $to),
            'disclaimer' => 'Seluruh angka bersifat deskriptif dan tidak dipakai untuk menilai karakter atau moral seseorang (§71).',
        ];
    }

    protected function attendanceMetrics(string $from, string $to): array
    {
        $rows = DB::table('prayer_attendance_records')
            ->whereDate('attendance_date', '>=', $from)->whereDate('attendance_date', '<=', $to)
            ->selectRaw('status, count(*) as total')->groupBy('status')->get()
            ->pluck('total', 'status')->all();

        $total = array_sum($rows);
        $present = (int) ($rows['PRESENT'] ?? 0);
        $late = (int) ($rows['LATE'] ?? 0);

        return [
            'total_records' => $total,
            'present' => $present,
            'late' => $late,
            'absent' => (int) ($rows['ABSENT'] ?? 0),
            'excused' => (int) ($rows['EXCUSED'] ?? 0),
            'authorized_absence' => (int) ($rows['AUTHORIZED_ABSENCE'] ?? 0),
            'unknown' => (int) ($rows['UNKNOWN'] ?? 0),
            'attendance_rate' => $total > 0 ? round((($present + $late) / $total) * 100, 2) : null,
            'late_rate' => $total > 0 ? round(($late / $total) * 100, 2) : null,
            'absence_rate' => $total > 0 ? round((((int) ($rows['ABSENT'] ?? 0) + (int) ($rows['AUTHORIZED_ABSENCE'] ?? 0)) / max(1, $total)) * 100, 2) : null,
        ];
    }

    protected function activityMetrics(string $from, string $to): array
    {
        $activities = DB::table('religious_activities')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->count();
        $participants = DB::table('religious_activity_participants')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->count();
        $attendance = DB::table('religious_activity_attendance')
            ->whereDate('attendance_date', '>=', $from)->whereDate('attendance_date', '<=', $to)
            ->selectRaw('status, count(*) as total')->groupBy('status')->get()->pluck('total', 'status')->all();

        return [
            'activities_created' => $activities,
            'participants' => $participants,
            'attendance_records' => array_sum($attendance),
            'present' => (int) ($attendance['PRESENT'] ?? 0),
            'late' => (int) ($attendance['LATE'] ?? 0),
            'by_type' => DB::table('religious_activities')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
                ->selectRaw('activity_type, count(*) as total')->groupBy('activity_type')->get()
                ->map(fn ($r): array => ['type' => $r->activity_type, 'total' => (int) $r->total])->all(),
            'kajian_sessions' => DB::table('religious_study_sessions')
                ->whereDate('session_date', '>=', $from)->whereDate('session_date', '<=', $to)->count(),
        ];
    }

    protected function utilizationMetrics(string $from, string $to): array
    {
        $rows = DB::table('mosque_utilization_logs')
            ->whereDate('log_date', '>=', $from)->whereDate('log_date', '<=', $to);

        return [
            'entries' => (clone $rows)->count(),
            'total_attendance' => (int) (clone $rows)->sum('attendance_count'),
            'average_attendance' => round((float) (clone $rows)->avg('attendance_count'), 2),
            'average_utilization_pct' => round((float) (clone $rows)->avg('utilization_pct'), 2),
            'peak' => (clone $rows)->orderByDesc('attendance_count')->first()?->attendance_count,
            'by_mosque' => (clone $rows)->selectRaw('mosque_id, count(*) as entries, sum(attendance_count) as attendance')
                ->groupBy('mosque_id')->get()
                ->map(fn ($r): array => [
                    'mosque_id' => $r->mosque_id, 'entries' => (int) $r->entries, 'attendance' => (int) $r->attendance,
                ])->all(),
        ];
    }

    protected function ramadanMetrics(string $from, string $to): array
    {
        return [
            'programs' => DB::table('ramadan_programs')->count(),
            'tarawih_sessions' => DB::table('tarawih_sessions')
                ->whereDate('session_date', '>=', $from)->whereDate('session_date', '<=', $to)->count(),
            'tarawih_attendance' => (int) DB::table('tarawih_sessions')
                ->whereDate('session_date', '>=', $from)->whereDate('session_date', '<=', $to)->sum('actual_participants'),
            'tahajjud_sessions' => DB::table('tahajjud_sessions')
                ->whereDate('session_date', '>=', $from)->whereDate('session_date', '<=', $to)->count(),
            'iftar_events' => DB::table('iftar_events')
                ->whereDate('event_date', '>=', $from)->whereDate('event_date', '<=', $to)->count(),
            'iftar_participants' => (int) DB::table('iftar_events')
                ->whereDate('event_date', '>=', $from)->whereDate('event_date', '<=', $to)->sum('actual_participants'),
            'suhoor_events' => DB::table('suhoor_events')
                ->whereDate('event_date', '>=', $from)->whereDate('event_date', '<=', $to)->count(),
            'itikaf_participants' => DB::table('itikaf_participants')->count(),
        ];
    }

    /** Laporan presensi shalat (§74). */
    public function prayerReport(string $from, string $to, string $groupBy = 'DATE', array $filters = []): array
    {
        $column = match (strtoupper($groupBy)) {
            'DORMITORY' => 'dormitory_id',
            'GRADE' => 'grade_level',
            'CLASS' => 'class_id',
            'PRAYER' => 'prayer_type',
            'MOSQUE' => 'mosque_id',
            default => 'attendance_date',
        };

        $rows = DB::table('prayer_attendance_records')
            ->whereDate('attendance_date', '>=', $from)->whereDate('attendance_date', '<=', $to)
            ->when(! empty($filters['campus_id']), fn ($q) => $q->where('campus_id', $filters['campus_id']))
            ->when(! empty($filters['mosque_id']), fn ($q) => $q->where('mosque_id', $filters['mosque_id']))
            ->selectRaw($column.' as bucket, status, count(*) as total')
            ->groupBy('bucket', 'status')->get();

        return [
            'from' => $from,
            'to' => $to,
            'group_by' => strtoupper($groupBy),
            'rows' => $rows->groupBy('bucket')->map(fn ($group, $bucket): array => [
                'bucket' => $bucket,
                'total' => (int) $group->sum('total'),
                'present' => (int) $group->where('status', 'PRESENT')->sum('total'),
                'late' => (int) $group->where('status', 'LATE')->sum('total'),
                'absent' => (int) $group->where('status', 'ABSENT')->sum('total'),
                'excused' => (int) $group->where('status', 'EXCUSED')->sum('total'),
            ])->values()->all(),
        ];
    }

    /** Laporan Jumat & khutbah (§74). */
    public function fridayReport(string $from, string $to, ?int $mosqueId = null): array
    {
        $rows = DB::table('friday_prayers')
            ->whereDate('prayer_date', '>=', $from)->whereDate('prayer_date', '<=', $to)
            ->when($mosqueId, fn ($q) => $q->where('mosque_id', $mosqueId))->orderByDesc('prayer_date')->get();

        return [
            'from' => $from,
            'to' => $to,
            'sessions' => $rows->count(),
            'total_attendance' => (int) $rows->sum('actual_attendance'),
            'average_attendance' => round((float) $rows->avg('actual_attendance'), 2),
            'with_khatib' => $rows->whereNotNull('khatib_id')->count(),
            'khutbahs' => DB::table('khutbahs')
                ->whereDate('prayer_date', '>=', $from)->whereDate('prayer_date', '<=', $to)->count(),
            'khutbah_by_status' => DB::table('khutbahs')->selectRaw('status, count(*) as total')
                ->groupBy('status')->get()->map(fn ($r): array => ['status' => $r->status, 'total' => (int) $r->total])->all(),
            'sessions_detail' => $rows->map(fn ($r): array => [
                'date' => $r->prayer_date, 'mosque_id' => $r->mosque_id,
                'khatib_id' => $r->khatib_id, 'topic' => $r->topic,
                'actual_attendance' => $r->actual_attendance, 'status' => $r->status,
            ])->all(),
        ];
    }

    /** Laporan kegiatan keagamaan (§74). */
    public function activityReport(string $from, string $to, array $filters = []): array
    {
        $activities = DB::table('religious_activities')
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when(! empty($filters['activity_type']), fn ($q) => $q->where('activity_type', strtoupper((string) $filters['activity_type'])))
            ->get();

        $participants = DB::table('religious_activity_participants')
            ->whereIn('activity_id', $activities->pluck('id')->all() ?: [0])->get();

        return [
            'from' => $from,
            'to' => $to,
            'activities' => $activities->count(),
            'by_status' => $activities->groupBy('status')->map->count()->all(),
            'participants' => $participants->count(),
            'completed' => $participants->whereNotNull('completed_at')->count(),
            'completion_rate' => $participants->count() > 0
                ? round(($participants->whereNotNull('completed_at')->count() / $participants->count()) * 100, 2)
                : null,
            'assessments' => DB::table('religious_activity_assessments')
                ->whereDate('assessment_date', '>=', $from)->whereDate('assessment_date', '<=', $to)->count(),
        ];
    }

    /** Laporan Ramadan (§74). */
    public function ramadanReport(int $programId): array
    {
        return app(RamadanService::class)->report($programId);
    }
}
