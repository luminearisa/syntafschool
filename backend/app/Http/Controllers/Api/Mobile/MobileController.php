<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\AttendanceStatus;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\ReportCard;
use App\Models\School;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Student;
use App\Models\TahfizNotification;
use App\Support\ContextState;
use App\Services\Boarding\BoardingPortalService;
use App\Services\Finance\FinancePortalService;
use App\Services\Mobile\ContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint ringkas untuk aplikasi mobile (wali murid · siswa · pegawai).
 *
 * Keamanan: bukan permission role, melainkan KEPEMILIKAN — setiap data anak
 * diverifikasi lewat `assertChild()` (404 bila bukan anak/diri akun ini).
 * Token wali/siswa hanya boleh memanggil `auth/*` dan `mobile/*`
 * (lihat `EnforceContext`).
 */
class MobileController extends BaseApiController
{
    public function __construct(
        private readonly ContextService $contexts,
        private readonly FinancePortalService $finance,
        private readonly BoardingPortalService $boarding,
    ) {}

    // ------------------------------------------------------------------
    // Wali murid & siswa
    // ------------------------------------------------------------------

    public function children(Request $request): JsonResponse
    {
        return $this->successResponse($this->childCards($request), 'Daftar anak berhasil diambil');
    }

    /** Beranda wali/siswa: kartu anak + angka penting. */
    public function home(Request $request): JsonResponse
    {
        $ctx = $request->attributes->get('mobile_context');
        $cards = $this->childCards($request);

        foreach ($cards as &$card) {
            $card['attendance'] = $this->attendanceSummary($card['id'], Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth());
            if ($ctx === ContextService::GUARDIAN) {
                $totals = $this->finance->summary($card['id'])['totals'] ?? [];
                $card['finance'] = [
                    'outstanding' => (float) ($totals['outstanding_total'] ?? 0),
                    'overdue' => (float) ($totals['overdue_total'] ?? 0),
                ];
            }
        }
        unset($card);

        return $this->successResponse([
            'context' => $ctx,
            'children' => $cards,
            'unread_notifications' => $request->user()->unreadNotifications()->count() + $this->tahfizNotifications($request)->whereNull('read_at')->count(),
        ], 'Beranda berhasil diambil');
    }

    public function attendance(Request $request, Student $student): JsonResponse
    {
        $id = $this->assertChild($request, $student->id);
        $month = $this->month($request);

        $rows = DB::table('student_attendances as a')
            ->join('attendance_statuses as s', 's.id', '=', 'a.attendance_status_id')
            ->leftJoin('subjects as sub', 'sub.id', '=', 'a.subject_id')
            ->where('a.student_id', $id)
            ->whereBetween('a.date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->orderByDesc('a.date')
            ->get(['a.date', 'a.time_in', 'a.notes', 's.code', 's.name as status', 's.color', 'sub.name as subject']);

        return $this->successResponse([
            'month' => $month->format('Y-m'),
            'summary' => $this->attendanceSummary($id, $month->copy()->startOfMonth(), $month->copy()->endOfMonth()),
            'records' => $rows,
        ], 'Absensi berhasil diambil');
    }

    public function scores(Request $request, Student $student): JsonResponse
    {
        $id = $this->assertChild($request, $student->id);

        $rows = DB::table('scores as sc')
            ->join('assessments as a', 'a.id', '=', 'sc.assessment_id')
            ->join('subjects as sub', 'sub.id', '=', 'a.subject_id')
            ->join('assessment_types as t', 't.id', '=', 'a.assessment_type_id')
            ->where('sc.student_id', $id)
            ->whereIn('a.status', ['verified', 'finalized'])
            ->whereNotNull('sc.score')
            ->orderByDesc('a.date')
            ->orderByDesc('sc.id')
            ->limit(200)
            ->get(['sc.score', 'sc.grade', 'sc.predicate', 'a.name', 'a.max_score', 'a.date', 'sub.name as subject', 't.name as type']);

        $bySubject = $rows->groupBy('subject')->map(function ($items, $subject) {
            $avg = $items->avg(fn ($r) => (float) $r->max_score > 0 ? ((float) $r->score / (float) $r->max_score) * 100 : null);

            return ['subject' => $subject, 'average' => $avg === null ? null : round($avg, 1), 'count' => $items->count()];
        })->values();

        return $this->successResponse(['subjects' => $bySubject, 'scores' => $rows], 'Nilai berhasil diambil');
    }

    /** Rapor yang sudah diterbitkan. */
    /** Unduh rapor terbit (PDF) — hanya milik anak sendiri dan hanya yang sudah diterbitkan. */
    public function reportCardPdf(Request $request, Student $student, ReportCard $reportCard)
    {
        $id = $this->assertChild($request, $student->id);

        abort_unless(
            (int) $reportCard->student_id === (int) $id && $reportCard->status === 'published',
            404,
            'Rapor tidak ditemukan.'
        );

        $reportCard->load(['student', 'schoolClass', 'semester', 'academicYear', 'items.subject']);
        $school = School::query()->where('is_active', true)->orderBy('id')->first();

        return Pdf::loadView('pdf.academic-report-card', compact('reportCard', 'school'))
            ->setPaper('a4')
            ->download('rapor-'.$reportCard->student?->nis.'-'.$reportCard->semester?->name.'.pdf');
    }

    public function reportCards(Request $request, Student $student): JsonResponse
    {
        $id = $this->assertChild($request, $student->id);

        $cards = ReportCard::query()
            ->with(['semester:id,name', 'academicYear:id,name', 'items.subject:id,name'])
            ->where('student_id', $id)
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->get()
            ->map(fn (ReportCard $c) => [
                'id' => $c->id,
                'type' => $c->type,
                'semester' => $c->semester?->name,
                'academic_year' => $c->academicYear?->name,
                'average_score' => $c->average_score,
                'rank' => $c->rank,
                'attendance_summary' => $c->attendance_summary,
                'homeroom_note' => $c->homeroom_note,
                'published_at' => $c->published_at?->toIso8601String(),
                'items' => $c->items->sortBy('position')->values()->map(fn ($i) => [
                    'subject' => $i->subject?->name,
                    'score' => $i->score,
                    'grade' => $i->grade,
                    'predicate' => $i->predicate,
                    'description' => $i->description,
                ]),
            ]);

        return $this->successResponse($cards, 'Rapor berhasil diambil');
    }

    /** Jadwal pelajaran kelas anak (semester aktif). */
    public function schedule(Request $request, Student $student): JsonResponse
    {
        $id = $this->assertChild($request, $student->id);
        $classId = $this->currentClassId($id);

        $rows = $classId === null ? collect() : DB::table('schedules as s')
            ->join('subjects as sub', 'sub.id', '=', 's.subject_id')
            ->join('semesters as sm', 'sm.id', '=', 's.semester_id')
            ->leftJoin('employees as e', 'e.id', '=', 's.employee_id')
            ->leftJoin('rooms as r', 'r.id', '=', 's.room_id')
            ->where('s.school_class_id', $classId)
            ->where('sm.is_active', true)
            ->orderBy('s.day_id')->orderBy('s.start_time')
            ->get(['s.day_id', 's.start_time', 's.end_time', 'sub.name as subject', 'e.full_name as teacher', 'r.name as room']);

        return $this->successResponse($rows, 'Jadwal berhasil diambil');
    }

    /** Keuangan anak — khusus wali murid. */
    public function finance(Request $request, Student $student): JsonResponse
    {
        $id = $this->assertChild($request, $student->id);

        $invoices = DB::table('finance_invoices')
            ->where('student_id', $id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->orderByDesc('issue_date')
            ->limit(50)
            ->get(['id', 'invoice_number', 'status', 'issue_date', 'due_date', 'total', 'paid_amount', 'remaining_amount', 'notes']);

        return $this->successResponse([
            'summary' => $this->finance->summary($id),
            'invoices' => $invoices,
        ], 'Keuangan anak berhasil diambil');
    }

    /** Ringkasan asrama anak (bila santri). */
    public function boarding(Request $request, Student $student): JsonResponse
    {
        $id = $this->assertChild($request, $student->id);

        $row = collect($this->boarding->children($request->user()))->firstWhere('student_id', $id);

        return $this->successResponse($row, 'Asrama berhasil diambil');
    }

    // ------------------------------------------------------------------
    // Pegawai
    // ------------------------------------------------------------------

    public function employeeHome(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $today = Carbon::today();

        $schedule = DB::table('schedules as s')
            ->join('subjects as sub', 'sub.id', '=', 's.subject_id')
            ->join('school_classes as c', 'c.id', '=', 's.school_class_id')
            ->join('semesters as sm', 'sm.id', '=', 's.semester_id')
            ->leftJoin('rooms as r', 'r.id', '=', 's.room_id')
            ->where('s.employee_id', $employee->id)
            ->where('sm.is_active', true)
            ->where('s.day_id', $today->dayOfWeekIso)
            ->orderBy('s.start_time')
            ->get(['s.id as schedule_id', 's.start_time', 's.end_time', 'sub.name as subject', 'c.name as class', 'r.name as room']);

        $journaled = DB::table('teaching_journals')
            ->where('teacher_id', $employee->id)
            ->whereDate('date', $today->toDateString())
            ->whereNull('deleted_at')
            ->pluck('schedule_id')
            ->filter()
            ->all();
        $schedule = $schedule->map(function ($row) use ($journaled) {
            $row->has_journal = in_array($row->schedule_id, $journaled, true);

            return $row;
        });

        $attendance = EmployeeAttendance::query()->with('attendanceStatus:id,code,name,color')
            ->where('employee_id', $employee->id)->whereDate('date', $today)->first();

        return $this->successResponse([
            'employee' => ['id' => $employee->id, 'full_name' => $employee->full_name, 'nip' => $employee->nip, 'is_teacher' => (bool) $employee->is_teacher],
            'today' => $today->toDateString(),
            'attendance' => $this->presentAttendance($attendance),
            'schedule' => $schedule,
            'pending_approvals' => $this->pendingApprovals($request),
            'unread_notifications' => $request->user()->unreadNotifications()->count() + $this->tahfizNotifications($request)->whereNull('read_at')->count(),
        ], 'Beranda pegawai berhasil diambil');
    }

    /**
     * Kelas yang diampu/diwalikan pegawai pada semester aktif — dasar pemilih
     * kelas untuk presensi siswa, jurnal, dan penilaian di aplikasi.
     */
    public function employeeClasses(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $semester = DB::table('semesters')->where('is_active', true)->first(['id', 'name', 'academic_year_id']);

        $taught = DB::table('class_subjects as cs')
            ->join('school_classes as c', 'c.id', '=', 'cs.school_class_id')
            ->join('subjects as sub', 'sub.id', '=', 'cs.subject_id')
            ->where('cs.employee_id', $employee->id)
            ->when($semester, fn ($q) => $q->where('cs.academic_year_id', $semester->academic_year_id))
            ->whereNull('c.deleted_at')
            ->orderBy('c.name')->orderBy('sub.name')
            ->get(['c.id as class_id', 'c.name as class_name', 'sub.id as subject_id', 'sub.name as subject_name']);

        $homeroom = DB::table('school_classes')
            ->where('homeroom_teacher_id', $employee->id)
            ->whereNull('deleted_at')
            ->get(['id as class_id', 'name as class_name']);

        $classes = $taught->groupBy('class_id')->map(fn ($rows) => [
            'class_id' => $rows->first()->class_id,
            'class_name' => $rows->first()->class_name,
            'is_homeroom' => $homeroom->contains('class_id', $rows->first()->class_id),
            'subjects' => $rows->map(fn ($r) => ['subject_id' => $r->subject_id, 'subject_name' => $r->subject_name])->values(),
        ]);

        foreach ($homeroom as $h) {
            if (! $classes->has($h->class_id)) {
                $classes->put($h->class_id, ['class_id' => $h->class_id, 'class_name' => $h->class_name, 'is_homeroom' => true, 'subjects' => []]);
            }
        }

        return $this->successResponse([
            'semester' => $semester,
            'classes' => $classes->sortBy('class_name')->values(),
        ], 'Kelas pegawai berhasil diambil');
    }

    /**
     * Lapor kerusakan aset lewat KODE label (hasil pindai QR/barcode atau ketik).
     *
     * Pencarian publik `assets/lookup/{code}` sengaja tidak mengembalikan id aset,
     * sehingga aplikasi tidak bisa memanggil `assets/{asset}/damage` langsung.
     * Di sini kode diselesaikan di server dengan aturan pencarian yang sama
     * (kode aset, barcode, token QR, tag, nomor seri) lalu diteruskan ke
     * DamageReportService — izin `asset.maintenance.request` dijaga di route.
     */
    public function reportDamage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120'],
            'severity' => ['required', 'string', 'in:MINOR,MODERATE,MAJOR,TOTAL'],
            'description' => ['required', 'string', 'max:2000'],
            'damage_type' => ['nullable', 'string', 'max:60'],
        ]);

        $asset = \App\Modules\Asset\Models\Asset::query()
            ->where(fn ($q) => $q->where('asset_code', $data['code'])
                ->orWhere('barcode', $data['code'])
                ->orWhere('qr_token', $data['code'])
                ->orWhere('asset_tag', $data['code'])
                ->orWhere('serial_number', $data['code']))
            ->first();

        if ($asset === null) {
            return $this->errorResponse('Kode aset tidak dikenali.', 404);
        }

        $report = app(\App\Modules\Asset\Application\Services\DamageReportService::class)->report($request->user(), [
            'asset_id' => $asset->id,
            'severity' => $data['severity'],
            'description' => $data['description'],
            'damage_type' => $data['damage_type'] ?? null,
        ]);

        return $this->successResponse([
            'report_number' => $report->report_number,
            'asset' => ['asset_code' => $asset->asset_code, 'name' => $asset->name],
            'severity' => $data['severity'],
        ], 'Laporan kerusakan dicatat.', 201);
    }

    public function employeeAttendance(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $month = $this->month($request);

        $rows = EmployeeAttendance::query()->with('attendanceStatus:id,code,name,color')
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->orderByDesc('date')->get();

        return $this->successResponse([
            'month' => $month->format('Y-m'),
            'summary' => $rows->groupBy(fn ($r) => $r->attendanceStatus?->name ?? '-')->map->count(),
            'records' => $rows->map(fn ($r) => $this->presentAttendance($r)),
        ], 'Presensi berhasil diambil');
    }

    public function checkIn(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $now = Carbon::now();

        $status = AttendanceStatus::query()->where('code', 'H')->first();
        if ($status === null) {
            return $this->errorResponse('Status kehadiran belum dikonfigurasi.', 422);
        }

        $existing = EmployeeAttendance::query()->where('employee_id', $employee->id)->whereDate('date', $now->toDateString())->first();
        if ($existing?->check_in) {
            return $this->errorResponse('Anda sudah check-in hari ini.', 422);
        }

        $row = $existing ?? new EmployeeAttendance(['employee_id' => $employee->id, 'date' => $now->toDateString()]);
        $row->forceFill([
            'attendance_status_id' => $row->attendance_status_id ?? $status->id,
            'check_in' => $now->format('H:i:s'),
            'notes' => $request->input('notes'),
            'recorded_by' => $request->user()->getKey(),
        ])->save();

        return $this->successResponse($this->presentAttendance($row->load('attendanceStatus')), 'Check-in berhasil', 201);
    }

    public function checkOut(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $now = Carbon::now();

        $row = EmployeeAttendance::query()->where('employee_id', $employee->id)->whereDate('date', $now->toDateString())->first();
        if ($row === null || ! $row->check_in) {
            return $this->errorResponse('Anda belum check-in hari ini.', 422);
        }
        if ($row->check_out) {
            return $this->errorResponse('Anda sudah check-out hari ini.', 422);
        }

        $row->forceFill(['check_out' => $now->format('H:i:s')])->save();

        return $this->successResponse($this->presentAttendance($row->load('attendanceStatus')), 'Check-out berhasil');
    }

    // ------------------------------------------------------------------
    // Notifikasi (semua peran) — kanal `database` bawaan Laravel
    // ------------------------------------------------------------------

    /**
     * Agregator: notifikasi Laravel (UKS, Aktivitas, LMS, Aset, Keuangan,
     * Perpustakaan, Workflow) + inbox Tahfiz yang punya tabel sendiri.
     * Id Tahfiz diawali `tahfiz-` supaya satu endpoint "baca" melayani keduanya.
     */
    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = $user->notifications()->latest()->limit(50)->get()->map(fn ($n) => [
            'id' => $n->id,
            'source' => 'system',
            'title' => $n->data['title'] ?? $n->data['subject'] ?? class_basename($n->type),
            'body' => $n->data['message'] ?? $n->data['body'] ?? null,
            'data' => $n->data,
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at?->toIso8601String(),
        ]);

        $tahfiz = $this->tahfizNotifications($request);

        $merged = $items->concat($tahfiz->map(fn (TahfizNotification $n) => [
            'id' => 'tahfiz-'.$n->id,
            'source' => 'tahfiz',
            'title' => $n->title,
            'body' => $n->body,
            'data' => $n->data,
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at?->toIso8601String(),
        ]))->sortByDesc('created_at')->take(60)->values();

        return $this->successResponse([
            'unread' => $user->unreadNotifications()->count() + $tahfiz->whereNull('read_at')->count(),
            'items' => $merged,
        ], 'Notifikasi berhasil diambil');
    }

    public function readNotification(Request $request, string $id): JsonResponse
    {
        if (str_starts_with($id, 'tahfiz-')) {
            $n = $this->tahfizNotifications($request)->firstWhere('id', (int) substr($id, 7));
            abort_if($n === null, 404, 'Notifikasi tidak ditemukan.');
            $n->forceFill(['read_at' => now()])->save();

            return $this->successResponse(null, 'Ditandai dibaca');
        }

        $n = $request->user()->notifications()->where('id', $id)->first();
        abort_if($n === null, 404, 'Notifikasi tidak ditemukan.');
        $n->markAsRead();

        return $this->successResponse(null, 'Ditandai dibaca');
    }

    public function readAllNotifications(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();
        $this->tahfizNotifications($request)->whereNull('read_at')
            ->each(fn (TahfizNotification $n) => $n->forceFill(['read_at' => now()])->save());

        return $this->successResponse(null, 'Semua notifikasi ditandai dibaca');
    }

    /** Inbox Tahfiz milik akun ini, disaring sesuai peran aktif. */
    private function tahfizNotifications(Request $request)
    {
        $audiences = match (ContextState::typeFor($request->user()->getKey())) {
            ContextService::GUARDIAN => [TahfizNotification::AUDIENCE_PARENT],
            ContextService::STUDENT => [TahfizNotification::AUDIENCE_PARTICIPANT],
            ContextService::EMPLOYEE => [TahfizNotification::AUDIENCE_INSTRUCTOR, TahfizNotification::AUDIENCE_MUSYRIF, TahfizNotification::AUDIENCE_ADMIN],
            default => TahfizNotification::AUDIENCES,
        };

        return TahfizNotification::query()
            ->where('user_id', $request->user()->getKey())
            ->whereIn('audience', $audiences)
            ->latest()->limit(50)->get();
    }

    // ------------------------------------------------------------------

    private function pendingApprovals(Request $request): int
    {
        $service = \App\Modules\Workflow\Application\Services\WorkflowInboxService::class;

        if (! class_exists($service) || ! $request->user()->can('workflow.approval.view')) {
            return 0;
        }

        return (int) (app($service)->counter($request->user())['pending'] ?? 0);
    }

    private function employee(Request $request): Employee
    {
        $id = $this->contexts->employeeId($request->user());
        abort_if($id === null, 403, 'Akun belum terhubung dengan data pegawai.');

        return Employee::query()->findOrFail($id);
    }

    /** @return list<int> */
    private function allowedStudentIds(Request $request): array
    {
        return $request->attributes->get('mobile_context') === ContextService::STUDENT
            ? array_filter([$this->contexts->studentId($request->user())])
            : $this->contexts->childStudentIds($request->user());
    }

    /** Kepemilikan: bukan anak/diri akun ini → 404 (tidak membocorkan keberadaan id). */
    private function assertChild(Request $request, int $studentId): int
    {
        abort_unless(in_array($studentId, $this->allowedStudentIds($request), true), 404, 'Data anak tidak ditemukan pada akun Anda.');

        if ($request->route()?->getActionMethod() === 'finance') {
            abort_unless($request->attributes->get('mobile_context') === ContextService::GUARDIAN, 403, 'Data keuangan hanya untuk wali murid.');
        }

        return $studentId;
    }

    /** @return list<array<string, mixed>> */
    private function childCards(Request $request): array
    {
        $ids = $this->allowedStudentIds($request);
        if ($ids === []) {
            return [];
        }

        return Student::query()->whereIn('id', $ids)->orderBy('full_name')->get()->map(function (Student $s) {
            $classId = $this->currentClassId($s->id);

            return [
                'id' => $s->id,
                'full_name' => $s->full_name,
                'nick_name' => $s->nick_name,
                'nis' => $s->nis,
                'photo_path' => $s->photo_path,
                'class_name' => $classId ? DB::table('school_classes')->where('id', $classId)->value('name') : null,
            ];
        })->all();
    }

    private function currentClassId(int $studentId): ?int
    {
        $id = DB::table('class_students as cs')
            ->join('academic_years as ay', 'ay.id', '=', 'cs.academic_year_id')
            ->where('cs.student_id', $studentId)
            ->where('cs.status', 'aktif')
            ->orderByDesc('ay.is_active')->orderByDesc('ay.start_date')
            ->value('cs.school_class_id');

        return $id === null ? null : (int) $id;
    }

    /** @return array<string, int> */
    private function attendanceSummary(int $studentId, Carbon $from, Carbon $to): array
    {
        return DB::table('student_attendances as a')
            ->join('attendance_statuses as s', 's.id', '=', 'a.attendance_status_id')
            ->where('a.student_id', $studentId)
            ->whereBetween('a.date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('s.code as code, count(*) as total')
            ->groupBy('s.code')
            ->pluck('total', 'code')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private function month(Request $request): Carbon
    {
        $m = (string) $request->query('month', '');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? Carbon::createFromFormat('Y-m-d', $m.'-01')->startOfDay() : Carbon::now()->startOfMonth();
    }

    /** @return array<string, mixed>|null */
    private function presentAttendance(?EmployeeAttendance $a): ?array
    {
        if ($a === null) {
            return null;
        }

        return [
            'date' => $a->date instanceof Carbon ? $a->date->toDateString() : (string) $a->date,
            'check_in' => $a->check_in,
            'check_out' => $a->check_out,
            'status' => $a->attendanceStatus?->name,
            'code' => $a->attendanceStatus?->code,
            'color' => $a->attendanceStatus?->color,
            'notes' => $a->notes,
        ];
    }
}
