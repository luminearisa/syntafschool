<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Contracts\FileStorageProviderInterface;
use App\Modules\LMS\Domain\Enums\ReportFormat;
use App\Modules\LMS\Domain\Enums\ReportKey;
use App\Modules\LMS\Domain\Enums\ReportStatus;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Jobs\GenerateLmsReportJob;
use App\Modules\LMS\Models\LmsReportExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Report Center LMS (§22).
 *
 * Seluruh laporan dibaca dari tabel yang sudah ada — tidak ada tabel ringkasan
 * tandingan, sehingga angka pada laporan selalu sama dengan yang dilihat guru
 * dan siswa di halaman lain.
 *
 * Laporan berat (>= `lms.reports.sync_row_limit` baris) dijalankan di antrean
 * lewat `GenerateLmsReportJob`; laporan kecil langsung dirender agar guru tidak
 * menunggu antrean untuk sesuatu yang selesai dalam sekejap.
 */
class LmsReportService
{
    use LogsLmsAudit;

    public function __construct(private readonly FileStorageProviderInterface $files) {}

    /**
     * Daftar laporan yang tersedia beserta kolomnya.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        return array_map(fn (ReportKey $key) => [
            'key' => $key->value,
            'label' => $key->label(),
            'columns' => $key->columns(),
            'formats' => array_values(array_filter(
                array_map(fn (ReportFormat $format) => $format->isAvailable() ? $format->value : null, ReportFormat::cases()),
            )),
        ], ReportKey::cases());
    }

    /**
     * Jalankan laporan (§22: Filter, Date Range, Course, Class, Teacher, Student).
     *
     * @param  array<string, mixed>  $filters
     * @return array{key:string,label:string,columns:list<string>,rows:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public function run(ReportKey $key, array $filters = [], int $limit = 2000): array
    {
        [$columns, $rows] = match ($key) {
            ReportKey::COURSE => [ReportKey::COURSE->columns(), $this->courseReport($filters, $limit)],
            ReportKey::ENROLLMENT => [ReportKey::ENROLLMENT->columns(), $this->enrollmentReport($filters, $limit)],
            ReportKey::COMPLETION => [ReportKey::COMPLETION->columns(), $this->completionReport($filters, $limit)],
            ReportKey::ASSIGNMENT => [ReportKey::ASSIGNMENT->columns(), $this->assignmentReport($filters, $limit)],
            ReportKey::QUIZ => [ReportKey::QUIZ->columns(), $this->quizReport($filters, $limit)],
            ReportKey::GRADE => [ReportKey::GRADE->columns(), $this->gradeReport($filters, $limit)],
            ReportKey::ATTENDANCE => [ReportKey::ATTENDANCE->columns(), $this->attendanceReport($filters, $limit)],
            ReportKey::COMPETENCY => [ReportKey::COMPETENCY->columns(), $this->competencyReport($filters, $limit)],
            ReportKey::OUTCOME => [ReportKey::OUTCOME->columns(), $this->outcomeReport($filters, $limit)],
            ReportKey::CERTIFICATE => [ReportKey::CERTIFICATE->columns(), $this->certificateReport($filters, $limit)],
            ReportKey::BADGE => [ReportKey::BADGE->columns(), $this->badgeReport($filters, $limit)],
            ReportKey::STUDENT_PROGRESS => [ReportKey::STUDENT_PROGRESS->columns(), $this->studentProgressReport($filters, $limit)],
            ReportKey::TEACHER_ACTIVITY => [ReportKey::TEACHER_ACTIVITY->columns(), $this->teacherReport($filters, $limit)],
            ReportKey::AT_RISK => [ReportKey::AT_RISK->columns(), $this->atRiskReport($filters, $limit)],
            ReportKey::ENGAGEMENT => [ReportKey::ENGAGEMENT->columns(), $this->engagementReport($filters, $limit)],
        };

        return [
            'key' => $key->value,
            'label' => $key->label(),
            'columns' => $columns,
            'rows' => $rows,
            'meta' => [
                'rows' => count($rows),
                'filters' => $filters,
                'generated_at' => now()->toIso8601String(),
                'truncated' => count($rows) >= $limit,
            ],
        ];
    }

    /**
     * Minta ekspor laporan (§22: Export CSV/XLSX/PDF).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function request(ReportKey $key, ReportFormat $format, array $filters, LmsActor $actor, ?string $idempotencyKey = null): array
    {
        if (! $actor->can('lms.report.export') && ! $actor->can('lms.manage')) {
            LmsFault::forbidden('Anda tidak berhak mengekspor laporan LMS.');
        }

        if (! $format->isAvailable()) {
            LmsFault::invalid('Format '.$format->value.' belum diaktifkan pada instalasi ini.');
        }

        if ($idempotencyKey !== null) {
            $existing = LmsReportExport::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing->toPayload() + ['duplicate' => true];
            }
        }

        $export = LmsReportExport::query()->create([
            'idempotency_key' => $idempotencyKey,
            'report_key' => $key->value,
            'format' => $format,
            'status' => ReportStatus::PENDING,
            'filters' => $filters,
            'requested_by' => $actor->id,
            'queued_at' => now(),
        ]);

        $count = $this->estimateRows($key, $filters);
        $sync = $count <= (int) config('lms.reports.sync_row_limit', 500);

        if ($sync) {
            return $this->generate($export) + ['duplicate' => false, 'queued' => false];
        }

        GenerateLmsReportJob::dispatch((int) $export->id);

        return $export->fresh()->toPayload() + ['duplicate' => false, 'queued' => true];
    }

    /** Render laporan menjadi berkas (dipanggil langsung atau oleh job). */
    public function generate(LmsReportExport $export): array
    {
        $key = ReportKey::tryFrom((string) $export->report_key);

        if ($key === null) {
            $export->fill(['status' => ReportStatus::FAILED, 'error_message' => 'Jenis laporan tidak dikenali.'])->save();

            return $export->fresh()->toPayload();
        }

        $export->fill(['status' => ReportStatus::PROCESSING])->save();

        try {
            $report = $this->run($key, (array) $export->filters);
            $format = $export->format ?? ReportFormat::CSV;
            $filename = 'laporan-'.strtolower($key->value).'-'.now()->format('Ymd-His').'.'.strtolower($format->value);
            $contents = $this->render($report, $format);

            $stored = $this->files->put('lms/reports', $filename, $contents, 'ADMIN_ONLY');

            $export->fill([
                'status' => ReportStatus::READY,
                'file_path' => $stored['path'],
                'storage_disk' => $stored['disk'],
                'rows_count' => count($report['rows']),
                'finished_at' => now(),
                'expires_at' => now()->addDays((int) config('lms.reports.export_retention_days', 7)),
            ])->save();

            $this->audit('LMS_REPORT_GENERATED', null, [
                'report_key' => $key->value,
                'rows' => count($report['rows']),
                'format' => $format->value,
            ]);
        } catch (\Throwable $exception) {
            $export->fill([
                'status' => ReportStatus::FAILED,
                'error_message' => mb_strimwidth($exception->getMessage(), 0, 500, '…'),
            ])->save();

            throw $exception;
        }

        return $export->fresh()->toPayload();
    }

    /**
     * Riwayat ekspor laporan.
     *
     * @return list<array<string, mixed>>
     */
    public function exports(int $limit = 25, ?int $requestedBy = null): array
    {
        return LmsReportExport::query()
            ->when($requestedBy !== null, fn ($query) => $query->where('requested_by', $requestedBy))
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(fn (LmsReportExport $export) => $export->toPayload())
            ->all();
    }

    /** Baca isi berkas laporan yang siap. */
    public function contents(LmsReportExport $export): string
    {
        if (! $export->isReady()) {
            LmsFault::invalid('Laporan belum siap diunduh.');
        }

        $disk = Storage::disk($export->storage_disk ?? $this->files->disk());

        if (! $disk->exists((string) $export->file_path)) {
            LmsFault::notFound('Berkas laporan sudah tidak tersedia.');
        }

        return (string) $disk->get((string) $export->file_path);
    }

    /** Perkiraan jumlah baris — menentukan laporan dijalankan langsung atau lewat antrean. */
    public function estimateRows(ReportKey $key, array $filters): int
    {
        return match ($key) {
            ReportKey::COURSE => DB::table('lms_courses')->count(),
            ReportKey::ENROLLMENT, ReportKey::COMPLETION, ReportKey::STUDENT_PROGRESS, ReportKey::AT_RISK, ReportKey::GRADE => DB::table('lms_enrollments')->count(),
            ReportKey::ASSIGNMENT => DB::table('lms_assignment_submissions')->count(),
            ReportKey::QUIZ => DB::table('lms_quiz_attempts')->count(),
            ReportKey::ATTENDANCE => DB::table('lms_live_session_attendance')->count(),
            ReportKey::COMPETENCY => DB::table('lms_student_competencies')->count(),
            ReportKey::OUTCOME => DB::table('lms_student_outcomes')->count(),
            ReportKey::CERTIFICATE => DB::table('lms_certificates')->count(),
            ReportKey::BADGE => DB::table('lms_badge_awards')->count(),
            ReportKey::TEACHER_ACTIVITY => DB::table('lms_course_teachers')->count(),
            ReportKey::ENGAGEMENT => DB::table('lms_learning_events')->count(),
        };
    }

    /** Render isi berkas laporan. */
    private function render(array $report, ReportFormat $format): string
    {
        // CSV adalah format dasar yang selalu tersedia; XLSX/PDF memerlukan
        // pustaka tambahan dan sengaja tidak dipaksakan di sini.
        if ($format !== ReportFormat::CSV) {
            LmsFault::invalid('Format '.$format->value.' belum tersedia; gunakan CSV.');
        }

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $report['columns']);

        foreach ($report['rows'] as $row) {
            fputcsv($handle, array_map(
                fn (string $column) => $this->stringify($row[$column] ?? null),
                $report['columns'],
            ));
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        // BOM agar Excel membaca UTF-8 dengan benar (nama beraksen/arab).
        return "\xEF\xBB\xBF".$contents;
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'ya' : 'tidak',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE) ?: '',
            default => (string) $value,
        };
    }

    // ------------------------------------------------------------------
    // Query per laporan
    // ------------------------------------------------------------------

    private function courseReport(array $filters, int $limit): array
    {
        return DB::table('lms_courses as c')
            ->leftJoin('lms_course_teachers as ct', 'ct.course_id', '=', 'c.id')
            ->leftJoin('users as u', 'u.id', '=', 'ct.user_id')
            ->leftJoin('lms_enrollments as e', function ($join): void {
                $join->on('e.course_id', '=', 'c.id')->where('e.role', '=', 'STUDENT');
            })
            ->leftJoin('lms_course_sections as s', 's.course_id', '=', 'c.id')
            ->leftJoin('lms_learning_items as i', 'i.course_id', '=', 'c.id')
            ->when(filled($filters['course_id'] ?? null), fn ($q) => $q->where('c.id', (int) $filters['course_id']))
            ->when(filled($filters['teacher_id'] ?? null), fn ($q) => $q->where('ct.user_id', (int) $filters['teacher_id']))
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('c.status', $filters['status']))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->where('c.created_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->where('c.created_at', '<=', $filters['to']))
            ->groupBy('c.id', 'c.name', 'c.code', 'c.status', 'u.name')
            ->orderBy('c.name')
            ->limit($limit)
            ->get([
                'c.name as course', 'c.code as code', 'u.name as teacher', 'c.status as status',
                DB::raw('count(distinct e.id) as students'),
                DB::raw('count(distinct s.id) as sections'),
                DB::raw('count(distinct i.id) as activities'),
                DB::raw('coalesce(round(avg(e.progress_percent), 2), 0) as completion_rate'),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function enrollmentReport(array $filters, int $limit): array
    {
        return DB::table('lms_enrollments as e')
            ->join('lms_courses as c', 'c.id', '=', 'e.course_id')
            ->when(filled($filters['course_id'] ?? null), fn ($q) => $q->where('e.course_id', (int) $filters['course_id']))
            ->when(filled($filters['student_id'] ?? null), fn ($q) => $q->where('e.student_id', (int) $filters['student_id']))
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('e.status', $filters['status']))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->where('e.created_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->where('e.created_at', '<=', $filters['to']))
            ->orderByDesc('e.id')
            ->limit($limit)
            ->get([
                'c.name as course', 'e.student_id as student', 'e.status as status', 'e.role as role',
                'e.created_at as enrolled_at', 'e.progress_percent as progress_percent',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function completionReport(array $filters, int $limit): array
    {
        return DB::table('lms_enrollments as e')
            ->join('lms_courses as c', 'c.id', '=', 'e.course_id')
            ->leftJoin('lms_course_progress as p', 'p.enrollment_id', '=', 'e.id')
            ->when(filled($filters['course_id'] ?? null), fn ($q) => $q->where('e.course_id', (int) $filters['course_id']))
            ->when(filled($filters['student_id'] ?? null), fn ($q) => $q->where('e.student_id', (int) $filters['student_id']))
            ->orderByDesc('e.id')
            ->limit($limit)
            ->get([
                'c.name as course', 'e.student_id as student',
                DB::raw('coalesce(p.progress_percent, e.progress_percent) as progress_percent'),
                DB::raw('coalesce(p.completed_items, 0) as completed_items'),
                DB::raw('coalesce(p.required_items, 0) as required_items'),
                'p.completed_at as completed_at', 'e.status as status',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function assignmentReport(array $filters, int $limit): array
    {
        return DB::table('lms_assignment_submissions as s')
            ->join('lms_assignments as a', 'a.id', '=', 's.assignment_id')
            ->join('lms_courses as c', 'c.id', '=', 'a.course_id')
            ->when(filled($filters['course_id'] ?? null), fn ($q) => $q->where('c.id', (int) $filters['course_id']))
            ->when(filled($filters['student_id'] ?? null), fn ($q) => $q->where('s.student_id', (int) $filters['student_id']))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->where('s.submitted_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->where('s.submitted_at', '<=', $filters['to']))
            ->orderByDesc('s.id')
            ->limit($limit)
            ->get([
                'c.name as course', 'a.title as activity', 's.student_id as student',
                's.submitted_at as submitted_at', 's.status as status',
                DB::raw('case when a.due_at is not null and s.submitted_at > a.due_at then 1 else 0 end as late'),
                's.grade as grade',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function quizReport(array $filters, int $limit): array
    {
        return DB::table('lms_quiz_attempts as t')
            ->join('lms_quizzes as q', 'q.id', '=', 't.quiz_id')
            ->join('lms_activities as a', 'a.id', '=', 'q.activity_id')
            ->join('lms_courses as c', 'c.id', '=', 'a.course_id')
            ->when(filled($filters['course_id'] ?? null), fn ($query) => $query->where('c.id', (int) $filters['course_id']))
            ->when(filled($filters['student_id'] ?? null), fn ($query) => $query->where('t.student_id', (int) $filters['student_id']))
            ->groupBy('t.quiz_id', 'a.title', 'c.name', 't.student_id')
            ->orderBy('a.title')
            ->limit($limit)
            ->get([
                'c.name as course', 'a.title as quiz', 't.student_id as student',
                DB::raw('count(t.id) as attempts'),
                DB::raw('max(t.raw_score) as best_score'),
                DB::raw('max(t.maximum_score) as maximum_score'),
                DB::raw('max(case when t.passed = 1 then 1 else 0 end) as passed'),
                DB::raw('max(t.submitted_at) as last_attempt_at'),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function gradeReport(array $filters, int $limit): array
    {
        return DB::table('lms_grade_releases as r')
            ->join('lms_gradebooks as g', 'g.id', '=', 'r.gradebook_id')
            ->join('lms_courses as c', 'c.id', '=', 'g.course_id')
            ->leftJoin('lms_grade_syncs as s', function ($join): void {
                $join->on('s.gradebook_id', '=', 'r.gradebook_id')->on('s.user_id', '=', 'r.user_id');
            })
            ->when(filled($filters['course_id'] ?? null), fn ($q) => $q->where('c.id', (int) $filters['course_id']))
            ->when(filled($filters['student_id'] ?? null), fn ($q) => $q->where('r.student_id', (int) $filters['student_id']))
            ->orderByDesc('r.id')
            ->limit($limit)
            ->get([
                'c.name as course', 'r.student_id as student', 'r.final_score as final_grade',
                'r.letter as letter', 'r.released_at as released_at',
                DB::raw("coalesce(s.status, 'NOT_SYNCED') as sync_status"),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function attendanceReport(array $filters, int $limit): array
    {
        return DB::table('lms_enrollments as e')
            ->join('lms_courses as c', 'c.id', '=', 'e.course_id')
            ->leftJoin('lms_live_sessions as ls', 'ls.course_id', '=', 'c.id')
            ->leftJoin('lms_live_session_attendance as la', function ($join): void {
                $join->on('la.live_session_id', '=', 'ls.id')->on('la.user_id', '=', 'e.user_id');
            })
            ->where('e.role', 'STUDENT')
            ->when(filled($filters['course_id'] ?? null), fn ($q) => $q->where('c.id', (int) $filters['course_id']))
            ->when(filled($filters['student_id'] ?? null), fn ($q) => $q->where('e.student_id', (int) $filters['student_id']))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->where('ls.start_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->where('ls.start_at', '<=', $filters['to']))
            ->groupBy('c.id', 'c.name', 'e.student_id')
            ->orderBy('c.name')
            ->limit($limit)
            ->get([
                'c.name as course', 'e.student_id as student',
                DB::raw('count(distinct ls.id) as live_sessions'),
                DB::raw('count(distinct case when la.status in (\'PRESENT\', \'LATE\') then la.id end) as attended'),
                DB::raw('case when count(distinct ls.id) = 0 then null else round(count(distinct case when la.status in (\'PRESENT\', \'LATE\') then la.id end) / count(distinct ls.id) * 100, 2) end as attendance_rate'),
                DB::raw('null as academic_rate'),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function competencyReport(array $filters, int $limit): array
    {
        return DB::table('lms_student_competencies as sc')
            ->join('lms_competencies as k', 'k.id', '=', 'sc.competency_id')
            ->leftJoin('lms_courses as c', 'c.id', '=', 'sc.course_id')
            ->when(filled($filters['course_id'] ?? null), fn ($q) => $q->where('sc.course_id', (int) $filters['course_id']))
            ->when(filled($filters['student_id'] ?? null), fn ($q) => $q->where('sc.student_id', (int) $filters['student_id']))
            ->orderByDesc('sc.id')
            ->limit($limit)
            ->get([
                'sc.student_id as student', 'k.name as competency', 'c.name as course',
                'sc.status as status',
                DB::raw('(select count(*) from lms_competency_evidence ev where ev.student_competency_id = sc.id) as evidence'),
                'sc.assessed_at as assessed_at',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function outcomeReport(array $filters, int $limit): array
    {
        return DB::table('lms_student_outcomes as so')
            ->join('lms_learning_outcomes as lo', 'lo.id', '=', 'so.learning_outcome_id')
            ->join('lms_courses as c', 'c.id', '=', 'so.course_id')
            ->when(filled($filters['course_id'] ?? null), fn ($q) => $q->where('so.course_id', (int) $filters['course_id']))
            ->when(filled($filters['student_id'] ?? null), fn ($q) => $q->where('so.student_id', (int) $filters['student_id']))
            ->orderByDesc('so.id')
            ->limit($limit)
            ->get([
                'c.name as course', DB::raw('coalesce(lo.code, lo.statement) as outcome'),
                'so.student_id as student',
                DB::raw('case when so.status in (\'ACHIEVED\', \'MASTERED\') then 1 else 0 end as achieved'),
                'so.score as score', 'so.assessed_at as assessed_at',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function certificateReport(array $filters, int $limit): array
    {
        return DB::table('lms_certificates as cert')
            ->join('lms_courses as c', 'c.id', '=', 'cert.course_id')
            ->leftJoin('lms_certificate_verifications as v', 'v.certificate_id', '=', 'cert.id')
            ->when(filled($filters['course_id'] ?? null), fn ($q) => $q->where('cert.course_id', (int) $filters['course_id']))
            ->when(filled($filters['student_id'] ?? null), fn ($q) => $q->where('cert.student_id', (int) $filters['student_id']))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->where('cert.issued_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->where('cert.issued_at', '<=', $filters['to']))
            ->groupBy('cert.id', 'cert.certificate_number', 'cert.student_id', 'c.name', 'cert.issued_at', 'cert.status')
            ->orderByDesc('cert.id')
            ->limit($limit)
            ->get([
                'cert.certificate_number as number', 'cert.student_id as student', 'c.name as course',
                'cert.issued_at as issued_at', 'cert.status as status',
                DB::raw('count(v.id) as verified_count'),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function badgeReport(array $filters, int $limit): array
    {
        return DB::table('lms_badge_awards as ba')
            ->join('lms_badges as b', 'b.id', '=', 'ba.badge_id')
            ->leftJoin('lms_courses as c', 'c.id', '=', 'ba.course_id')
            ->when(filled($filters['course_id'] ?? null), fn ($q) => $q->where('ba.course_id', (int) $filters['course_id']))
            ->when(filled($filters['student_id'] ?? null), fn ($q) => $q->where('ba.student_id', (int) $filters['student_id']))
            ->orderByDesc('ba.id')
            ->limit($limit)
            ->get([
                'b.name as badge', 'ba.student_id as student', 'c.name as course',
                'ba.source as source', 'ba.awarded_at as awarded_at',
                DB::raw('coalesce(b.points, 0) as points'),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function studentProgressReport(array $filters, int $limit): array
    {
        return DB::table('lms_enrollments as e')
            ->leftJoin('lms_learning_streaks as s', function ($join): void {
                $join->on('s.user_id', '=', 'e.user_id')->on('s.course_id', '=', 'e.course_id');
            })
            ->when(filled($filters['student_id'] ?? null), fn ($q) => $q->where('e.student_id', (int) $filters['student_id']))
            ->when(filled($filters['course_id'] ?? null), fn ($q) => $q->where('e.course_id', (int) $filters['course_id']))
            ->where('e.role', 'STUDENT')
            ->groupBy('e.student_id')
            ->orderBy('e.student_id')
            ->limit($limit)
            ->get([
                'e.student_id as student',
                DB::raw('count(distinct e.course_id) as courses'),
                DB::raw('round(avg(e.progress_percent), 2) as average_progress'),
                DB::raw("count(distinct case when e.status = 'COMPLETED' then e.id end) as completed_courses"),
                DB::raw('coalesce(sum(e.xp_points), 0) as xp'),
                DB::raw('coalesce(max(s.current_streak), 0) as streak'),
                DB::raw('coalesce(max(e.health_status), \'UNKNOWN\') as health'),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function teacherReport(array $filters, int $limit): array
    {
        return DB::table('lms_course_teachers as ct')
            ->join('lms_courses as c', 'c.id', '=', 'ct.course_id')
            ->leftJoin('lms_learning_items as i', 'i.course_id', '=', 'c.id')
            ->leftJoin('lms_activity_grades as g', 'g.course_id', '=', 'c.id')
            ->when(filled($filters['teacher_id'] ?? null), fn ($q) => $q->where('ct.user_id', (int) $filters['teacher_id']))
            ->when(filled($filters['course_id'] ?? null), fn ($q) => $q->where('ct.course_id', (int) $filters['course_id']))
            ->groupBy('ct.user_id')
            ->orderBy('ct.user_id')
            ->limit($limit)
            ->get([
                'ct.user_id as teacher',
                DB::raw('count(distinct c.id) as courses'),
                DB::raw('count(distinct i.id) as activities'),
                DB::raw('count(distinct g.id) as graded'),
                DB::raw('count(distinct case when g.feedback is not null then g.id end) as feedback_given'),
                DB::raw('max(g.graded_at) as last_activity_at'),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function atRiskReport(array $filters, int $limit): array
    {
        return DB::table('lms_enrollments as e')
            ->join('lms_courses as c', 'c.id', '=', 'e.course_id')
            ->whereIn('e.health_status', ['AT_RISK', 'INACTIVE'])
            ->when(filled($filters['course_id'] ?? null), fn ($q) => $q->where('e.course_id', (int) $filters['course_id']))
            ->when(filled($filters['student_id'] ?? null), fn ($q) => $q->where('e.student_id', (int) $filters['student_id']))
            ->orderBy('e.health_status')
            ->limit($limit)
            ->get([
                'e.student_id as student', 'c.name as course', 'e.health_status as status',
                DB::raw("case e.health_status
                    when 'INACTIVE' then concat('Tidak ada aktivitas sejak ', coalesce(date(e.last_event_at), 'awal pendaftaran'))
                    when 'AT_RISK' then 'Progres rendah atau banyak tenggat terlewat'
                    else '-' end as reason"),
                'e.last_event_at as last_activity_at',
                // Tenggat aktivitas yang sudah lewat dan belum tuntas oleh siswa ini.
                DB::raw('(select count(*) from lms_activities a
                    where a.course_id = e.course_id
                      and a.due_at is not null
                      and a.due_at < now()
                      and not exists (
                          select 1 from lms_activity_completions ac
                          where ac.learning_item_id = a.learning_item_id and ac.user_id = e.user_id and ac.status = \'COMPLETE\'
                      )) as overdue_count'),
                'e.progress_percent as progress_percent',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function engagementReport(array $filters, int $limit): array
    {
        return DB::table('lms_courses as c')
            ->leftJoin('lms_learning_events as ev', 'ev.course_id', '=', 'c.id')
            // Forum menempel pada aktivitas, dan aktivitas pada course.
            ->leftJoin('lms_activities as la_forum', function ($join): void {
                $join->on('la_forum.course_id', '=', 'c.id')->where('la_forum.activity_type', '=', 'FORUM');
            })
            ->leftJoin('lms_forums as f', 'f.activity_id', '=', 'la_forum.id')
            ->leftJoin('lms_forum_discussions as fd', 'fd.forum_id', '=', 'f.id')
            ->leftJoin('lms_forum_posts as fp', 'fp.discussion_id', '=', 'fd.id')
            ->leftJoin('lms_live_sessions as ls', 'ls.course_id', '=', 'c.id')
            ->leftJoin('lms_live_session_attendance as la', 'la.live_session_id', '=', 'ls.id')
            ->when(filled($filters['course_id'] ?? null), fn ($q) => $q->where('c.id', (int) $filters['course_id']))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->where('ev.occurred_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->where('ev.occurred_at', '<=', $filters['to']))
            ->groupBy('c.id', 'c.name')
            ->orderBy('c.name')
            ->limit($limit)
            ->get([
                'c.name as course',
                DB::raw('count(distinct ev.id) as events'),
                DB::raw('count(distinct ev.user_id) as active_students'),
                DB::raw('count(distinct fp.id) as forum_posts'),
                DB::raw('count(distinct la.id) as live_attendance'),
                DB::raw('null as average_session_minutes'),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
