<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Attendance\BulkAttendanceRequest;
use App\Http\Requests\Attendance\StudentAttendanceRequest;
use App\Http\Resources\StudentAttendanceResource;
use App\Models\AttendanceStatus;
use App\Models\ClassStudent;
use App\Models\EffectiveDay;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Services\Attendance\AttendanceRecapService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Presensi siswa.
 *
 * Menjawab: "hari ini siapa hadir di kelas ini, dan bagaimana rekap
 * kehadiran siswa sepanjang semester?"
 *
 * Aturan inti:
 * - Satu siswa hanya punya satu presensi per tanggal per jam pelajaran.
 * - Tanggal presensi harus di dalam rentang semester.
 * - Presensi hanya dicatat pada hari efektif (bila data hari efektif tersedia).
 * - Siswa harus benar-benar anggota kelas tersebut.
 * - Status sakit/izin mewajibkan jenis izin.
 */
class StudentAttendanceController extends BaseApiController
{
    public function __construct(
        protected AttendanceRecapService $recap,
    ) {}

    /** Kolom yang boleh difilter lewat query string. */
    protected array $filterable = [
        'student_id',
        'school_class_id',
        'semester_id',
        'subject_id',
        'schedule_id',
        'attendance_status_id',
        'status',
    ];

    /** Relasi standar agar frontend tidak perlu request tambahan. */
    protected array $with = [
        'student',
        'schoolClass',
        'semester',
        'subject',
        'attendanceStatus',
        'leaveType',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = StudentAttendance::query()->with($this->with);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $this->filterable, true)) {
                continue;
            }
            $query->where($column, $value);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('date', '<=', $to);
        }

        if ($search = $request->query('search')) {
            $query->whereHas('student', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('nis', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('date')->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                StudentAttendanceResource::collection($query->get()),
                'Daftar presensi siswa berhasil diambil',
            );
        }

        return $this->successResponse(
            StudentAttendanceResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar presensi siswa berhasil diambil',
        );
    }

    public function show(StudentAttendance $attendance): JsonResponse
    {
        return $this->successResponse(
            new StudentAttendanceResource($attendance->load($this->with)),
            'Detail presensi siswa berhasil diambil',
        );
    }

    public function store(StudentAttendanceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->assertMayRecordClass($request, (int) $data['class_id']);
        $this->assertStudentInClass((int) $data['student_id'], (int) $data['class_id']);
        $this->assertDateInSemester((int) $data['semester_id'], $data['date']);
        $this->assertSchoolDay((int) $data['semester_id'], $data['date']);
        $this->assertLeaveType($data);
        $this->assertNoDuplicate(
            (int) $data['student_id'],
            $data['date'],
            $data['schedule_id'] ?? null,
        );

        $data = $this->mapClassColumn($data);

        $data['recorded_by'] = $request->user()?->id;
        $data['status'] = $data['status'] ?? StudentAttendance::STATUS_RECORDED;

        if (! empty($data['time_in'])) {
            $data['time_in'] .= ':00';
        }

        $attendance = StudentAttendance::create($data);

        return $this->createdResponse(
            new StudentAttendanceResource($attendance->load($this->with)),
            'Presensi siswa berhasil dicatat',
        );
    }

    public function update(StudentAttendanceRequest $request, StudentAttendance $attendance): JsonResponse
    {
        if ($attendance->isLocked()) {
            throw ValidationException::withMessages([
                'status' => 'Presensi ini terkunci. Ajukan koreksi presensi untuk mengubahnya.',
            ]);
        }

        $data = $request->validated();

        $this->assertStudentInClass((int) $data['student_id'], (int) $data['class_id']);
        $this->assertDateInSemester((int) $data['semester_id'], $data['date']);
        $this->assertSchoolDay((int) $data['semester_id'], $data['date']);
        $this->assertLeaveType($data);
        $this->assertNoDuplicate(
            (int) $data['student_id'],
            $data['date'],
            $data['schedule_id'] ?? null,
            $attendance->id,
        );

        if (array_key_exists('time_in', $data) && $data['time_in']) {
            $data['time_in'] .= ':00';
        }

        $attendance->update($this->mapClassColumn($data));

        return $this->successResponse(
            new StudentAttendanceResource($attendance->refresh()->load($this->with)),
            'Presensi siswa berhasil diperbarui',
        );
    }

    public function destroy(StudentAttendance $attendance): JsonResponse
    {
        if ($attendance->isLocked()) {
            throw ValidationException::withMessages([
                'status' => 'Presensi ini terkunci dan tidak bisa dihapus.',
            ]);
        }

        $attendance->delete();

        return $this->successResponse(null, 'Presensi siswa berhasil dihapus');
    }

    /**
     * Daftar siswa satu kelas beserta status presensinya pada tanggal tertentu.
     *
     * Dipakai form input massal: guru tidak perlu mencari siswa satu per satu.
     */
    public function sheet(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'date' => ['required', 'date'],
            'schedule_id' => ['nullable', 'integer', 'exists:schedules,id'],
        ], [], [
            'class_id' => 'kelas',
            'semester_id' => 'semester',
            'date' => 'tanggal',
            'schedule_id' => 'jadwal',
        ]);

        $this->assertMayRecordClass($request, (int) $data['class_id']);

        $students = $this->studentsInClass((int) $data['class_id']);

        $existing = StudentAttendance::query()
            ->with('attendanceStatus')
            ->where('school_class_id', $data['class_id'])
            ->where('semester_id', $data['semester_id'])
            ->whereDate('date', $data['date'])
            ->when(
                $data['schedule_id'] ?? null,
                fn ($q, $scheduleId) => $q->where('schedule_id', $scheduleId),
            )
            ->get()
            ->keyBy('student_id');

        $items = $students->map(function (Student $student) use ($existing) {
            $record = $existing->get($student->id);

            return [
                'student_id' => $student->id,
                'nis' => $student->nis,
                'full_name' => $student->full_name,
                'attendance_id' => $record?->id,
                'attendance_status_id' => $record?->attendance_status_id,
                'status_code' => $record?->attendanceStatus?->code,
                'leave_type_id' => $record?->leave_type_id,
                'time_in' => $record?->time_in ? substr($record->time_in, 0, 5) : null,
                'notes' => $record?->notes,
                'recorded' => $record !== null,
            ];
        })->values();

        return $this->successResponse([
            'class_id' => (int) $data['class_id'],
            'semester_id' => (int) $data['semester_id'],
            'date' => $data['date'],
            'is_school_day' => $this->isSchoolDay((int) $data['semester_id'], $data['date']),
            'total' => $items->count(),
            'recorded' => $items->where('recorded', true)->count(),
            'items' => $items,
        ], 'Daftar presensi kelas berhasil diambil');
    }

    /**
     * Input presensi massal satu kelas pada satu tanggal.
     *
     * Bersifat upsert: entri yang sudah ada diperbarui, yang belum dibuat.
     * Jadi guru boleh menekan "Simpan" dua kali tanpa membuat data ganda.
     */
    public function bulk(BulkAttendanceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->assertMayRecordClass($request, (int) $data['class_id']);
        $this->assertDateInSemester((int) $data['semester_id'], $data['date']);
        $this->assertSchoolDay((int) $data['semester_id'], $data['date']);

        $memberIds = $this->studentsInClass((int) $data['class_id'])->pluck('id')->all();

        $statuses = AttendanceStatus::query()->get()->keyBy('id');

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($data, $memberIds, $statuses, $request, &$created, &$updated) {
            foreach ($data['entries'] as $index => $entry) {
                $studentId = (int) $entry['student_id'];

                if (! in_array($studentId, $memberIds, true)) {
                    throw ValidationException::withMessages([
                        "entries.{$index}.student_id" => 'Siswa ini bukan anggota kelas yang dipilih.',
                    ]);
                }

                $status = $statuses->get((int) $entry['attendance_status_id']);

                if ($status === null) {
                    throw ValidationException::withMessages([
                        "entries.{$index}.attendance_status_id" => 'Status presensi tidak dikenal.',
                    ]);
                }

                if (in_array($status->code, AttendanceStatus::CODES_REQUIRING_LEAVE_TYPE, true)
                    && empty($entry['leave_type_id'])) {
                    throw ValidationException::withMessages([
                        "entries.{$index}.leave_type_id" => "Jenis izin wajib diisi untuk status {$status->name}.",
                    ]);
                }

                $timeIn = $entry['time_in'] ?? null;

                if ($timeIn) {
                    $timeIn .= ':00';
                }

                $existing = StudentAttendance::query()
                    ->where('student_id', $studentId)
                    ->whereDate('date', $data['date'])
                    ->when(
                        $data['schedule_id'] ?? null,
                        fn ($q, $scheduleId) => $q->where('schedule_id', $scheduleId),
                        fn ($q) => $q->whereNull('schedule_id'),
                    )
                    ->first();

                $payload = [
                    'school_class_id' => $data['class_id'],
                    'semester_id' => $data['semester_id'],
                    'schedule_id' => $data['schedule_id'] ?? null,
                    'subject_id' => $data['subject_id'] ?? null,
                    'date' => $data['date'],
                    'attendance_status_id' => $entry['attendance_status_id'],
                    'leave_type_id' => $entry['leave_type_id'] ?? null,
                    'time_in' => $timeIn,
                    'notes' => $entry['notes'] ?? null,
                    'recorded_by' => $request->user()?->id,
                ];

                if ($existing) {
                    if ($existing->isLocked()) {
                        throw ValidationException::withMessages([
                            "entries.{$index}.student_id" => 'Presensi siswa ini terkunci. Ajukan koreksi presensi.',
                        ]);
                    }

                    $existing->update($payload);
                    $updated++;

                    continue;
                }

                StudentAttendance::create($payload + [
                    'student_id' => $studentId,
                    'status' => StudentAttendance::STATUS_RECORDED,
                ]);
                $created++;
            }
        });

        return $this->successResponse([
            'class_id' => (int) $data['class_id'],
            'semester_id' => (int) $data['semester_id'],
            'date' => $data['date'],
            'created' => $created,
            'updated' => $updated,
            'total' => $created + $updated,
        ], 'Presensi kelas berhasil disimpan');
    }

    /**
     * Rekap presensi satu siswa pada satu semester.
     */
    public function recapStudent(Request $request, Student $student): JsonResponse
    {
        $semesterId = $request->integer('semester_id') ?: $this->recap->activeSemesterId();

        if (! $semesterId) {
            throw ValidationException::withMessages([
                'semester_id' => 'Semester aktif belum ditetapkan.',
            ]);
        }

        return $this->successResponse(
            $this->recap->forStudent((int) $student->id, (int) $semesterId),
            'Rekap presensi siswa berhasil diambil',
        );
    }

    /**
     * Rekap presensi seluruh siswa dalam satu kelas.
     */
    public function recapClass(Request $request, SchoolClass $class): JsonResponse
    {
        $semesterId = $request->integer('semester_id') ?: $this->recap->activeSemesterId();

        if (! $semesterId) {
            throw ValidationException::withMessages([
                'semester_id' => 'Semester aktif belum ditetapkan.',
            ]);
        }

        return $this->successResponse(
            $this->recap->forClass((int) $class->id, (int) $semesterId),
            'Rekap presensi kelas berhasil diambil',
        );
    }

    // -----------------------------------------------------------------
    // Pemeriksaan aturan bisnis
    // -----------------------------------------------------------------

    /**
     * API memakai nama ramah `class_id`; kolomnya `school_class_id`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mapClassColumn(array $data): array
    {
        if (array_key_exists('class_id', $data)) {
            $data['school_class_id'] = $data['class_id'];
            unset($data['class_id']);
        }

        return $data;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Student>
     */
    /** Guru hanya boleh mencatat presensi kelas yang diampu/walikelasinya (lihat TeachingAccess). */
    protected function assertMayRecordClass(Request $request, int $classId): void
    {
        if (! app(\App\Services\Academic\TeachingAccess::class)->mayRecordClass($request->user(), $classId)) {
            abort(403, 'Anda bukan wali kelas atau pengampu kelas ini.');
        }
    }

    protected function studentsInClass(int $classId)
    {
        return Student::query()
            ->whereIn('id', ClassStudent::query()
                ->where('school_class_id', $classId)
                ->where('status', 'aktif')
                ->pluck('student_id'))
            ->orderBy('full_name')
            ->get();
    }

    protected function assertStudentInClass(int $studentId, int $classId): void
    {
        $member = ClassStudent::query()
            ->where('school_class_id', $classId)
            ->where('student_id', $studentId)
            ->where('status', 'aktif')
            ->exists();

        if (! $member) {
            throw ValidationException::withMessages([
                'student_id' => 'Siswa ini bukan anggota aktif kelas yang dipilih.',
            ]);
        }
    }

    protected function assertDateInSemester(int $semesterId, ?string $date): void
    {
        if (! $date) {
            return;
        }

        $semester = Semester::find($semesterId);

        if (! $semester) {
            return;
        }

        $value = Carbon::parse($date)->toDateString();

        $outside = ($semester->start_date && $value < $semester->start_date->toDateString())
            || ($semester->end_date && $value > $semester->end_date->toDateString());

        if ($outside) {
            throw ValidationException::withMessages([
                'date' => 'Tanggal presensi harus berada di dalam rentang semester yang dipilih.',
            ]);
        }
    }

    /**
     * Hari efektif = hari yang boleh dicatat presensinya.
     *
     * Kalau semester itu belum punya data hari efektif sama sekali (Tahap A4
     * belum dijalankan untuk semester tersebut), pemeriksaan dilewati supaya
     * modul presensi tidak ikut mati.
     */
    protected function assertSchoolDay(int $semesterId, ?string $date): void
    {
        if (! $date) {
            return;
        }

        $hasAny = EffectiveDay::query()->where('semester_id', $semesterId)->exists();

        if (! $hasAny) {
            return;
        }

        $isSchoolDay = EffectiveDay::query()
            ->where('semester_id', $semesterId)
            ->whereDate('date', $date)
            ->value('is_school_day');

        if ($isSchoolDay === false || $isSchoolDay === 0) {
            throw ValidationException::withMessages([
                'date' => 'Tanggal ini bukan hari efektif (libur atau akhir pekan).',
            ]);
        }
    }

    protected function isSchoolDay(int $semesterId, string $date): ?bool
    {
        $value = EffectiveDay::query()
            ->where('semester_id', $semesterId)
            ->whereDate('date', $date)
            ->value('is_school_day');

        return $value === null ? null : (bool) $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertLeaveType(array $data): void
    {
        $status = AttendanceStatus::find($data['attendance_status_id'] ?? null);

        if ($status === null) {
            return;
        }

        if (in_array($status->code, AttendanceStatus::CODES_REQUIRING_LEAVE_TYPE, true)
            && empty($data['leave_type_id'])) {
            throw ValidationException::withMessages([
                'leave_type_id' => "Jenis izin wajib diisi untuk status {$status->name}.",
            ]);
        }
    }

    protected function assertNoDuplicate(
        int $studentId,
        string $date,
        mixed $scheduleId,
        ?int $ignoreId = null,
    ): void {
        $exists = StudentAttendance::query()
            ->where('student_id', $studentId)
            ->whereDate('date', $date)
            ->when(
                $scheduleId,
                fn ($q, $value) => $q->where('schedule_id', $value),
                fn ($q) => $q->whereNull('schedule_id'),
            )
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'student_id' => 'Presensi siswa ini pada tanggal tersebut sudah tercatat.',
            ]);
        }
    }
}
