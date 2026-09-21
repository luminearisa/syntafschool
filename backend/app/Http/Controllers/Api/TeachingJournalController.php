<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Teaching\CompleteJournalRequest;
use App\Http\Requests\Teaching\TeachingJournalRequest;
use App\Http\Resources\TeachingJournalResource;
use App\Models\ClassSubject;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\Semester;
use App\Models\TeachingJournal;
use App\Traits\ResolvesTeachingContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Jurnal mengajar.
 *
 * Menjawab: "jam apa saja yang saya ajar hari ini, mana yang sudah/belum
 * dijurnal, dan berapa JP yang sudah terealisasi?"
 *
 * Aturan inti:
 * - Guru hanya boleh membuat jurnal untuk kelas & mapel yang benar-benar diampu
 *   (`class_subjects`) dan atas namanya sendiri.
 * - Tanggal jurnal harus berada di dalam rentang semester.
 * - Realisasi JP tidak boleh melebihi alokasi mapel di kelas tersebut.
 * - Satu guru tidak boleh punya dua jurnal pada jam yang sama di tanggal sama.
 * - Jurnal `completed` terkunci, kecuali pemanggil memegang `academic.approve`.
 */
class TeachingJournalController extends BaseApiController
{
    use ResolvesTeachingContext;

    /** Kolom yang boleh difilter lewat query string. */
    protected array $filterable = [
        'teacher_id',
        'class_id',
        'subject_id',
        'semester_id',
        'status',
    ];

    /** Relasi standar agar frontend tidak perlu request tambahan. */
    protected array $with = [
        'schoolClass',
        'subject',
        'teacher',
        'semester',
        'timeSlot',
        'schedule',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = TeachingJournal::query()->with($this->with);

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
            $query->where(function ($q) use ($search) {
                $q->where('topic', 'like', "%{$search}%")
                    ->orWhere('material', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('date')->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TeachingJournalResource::collection($query->get()),
                'Daftar jurnal mengajar berhasil diambil',
            );
        }

        return $this->successResponse(
            TeachingJournalResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar jurnal mengajar berhasil diambil',
        );
    }

    public function store(TeachingJournalRequest $request): JsonResponse
    {
        $data = $request->validated();

        $teacherId = $this->resolveTeacherId($request);

        if (! $teacherId) {
            throw ValidationException::withMessages([
                'teacher_id' => 'Guru wajib dipilih.',
            ]);
        }

        $this->assertTeacherOwnership($request, $teacherId);
        $data['teacher_id'] = $teacherId;

        $this->applyScheduleDefaults($data);

        $this->assertTeachesSubject((int) $data['class_id'], (int) $data['subject_id'], $teacherId);
        $this->assertDateInSemester((int) $data['semester_id'], $data['date']);
        $this->assertRealizedJp((int) $data['class_id'], (int) $data['subject_id'], $data['realized_jp'] ?? null);
        $this->assertSlotFree($teacherId, $data['date'], $data['time_slot_id'] ?? null);

        $data['status'] = $data['status'] ?? TeachingJournal::STATUS_PLANNED;
        $data['created_by'] = $request->user()?->id;

        $journal = TeachingJournal::create($data);

        return $this->createdResponse(
            new TeachingJournalResource($journal->load($this->with)),
            'Jurnal mengajar berhasil dibuat',
        );
    }

    public function show(TeachingJournal $journal): JsonResponse
    {
        return $this->successResponse(
            new TeachingJournalResource($journal->load($this->with)),
            'Detail jurnal mengajar berhasil diambil',
        );
    }

    public function update(TeachingJournalRequest $request, TeachingJournal $journal): JsonResponse
    {
        $user = $request->user();
        $canApprove = (bool) $user?->can('academic.approve');
        $linked = $this->linkedEmployeeId($user);

        if ($linked !== null && $linked !== (int) $journal->teacher_id && ! $canApprove) {
            abort(403, 'Anda hanya boleh mengubah jurnal milik sendiri.');
        }

        if ($journal->status === TeachingJournal::STATUS_COMPLETED && ! $canApprove) {
            throw ValidationException::withMessages([
                'status' => 'Jurnal yang sudah selesai terkunci dan tidak bisa diubah.',
            ]);
        }

        $data = $request->validated();
        $newStatus = $data['status'] ?? $journal->status;

        if ($newStatus === TeachingJournal::STATUS_COMPLETED
            && $journal->status !== TeachingJournal::STATUS_COMPLETED
            && ! $canApprove) {
            throw ValidationException::withMessages([
                'status' => 'Jurnal hanya bisa diselesaikan lewat aksi "Selesaikan" (butuh izin khusus bila dibatalkan).',
            ]);
        }

        $teacherId = (int) ($data['teacher_id'] ?? $journal->teacher_id);

        if (! $canApprove) {
            $this->assertTeacherOwnership($request, $teacherId);
        }

        $data['teacher_id'] = $teacherId;

        $classId = (int) ($data['class_id'] ?? $journal->class_id);
        $subjectId = (int) ($data['subject_id'] ?? $journal->subject_id);
        $semesterId = (int) ($data['semester_id'] ?? $journal->semester_id);
        $date = $data['date'] ?? $journal->date?->toDateString();
        $timeSlotId = $data['time_slot_id'] ?? $journal->time_slot_id;

        $this->assertTeachesSubject($classId, $subjectId, $teacherId);
        $this->assertDateInSemester($semesterId, $date);
        $this->assertRealizedJp($classId, $subjectId, $data['realized_jp'] ?? null);
        $this->assertSlotFree($teacherId, $date, $timeSlotId, $journal->id);

        $journal->update($data);

        return $this->successResponse(
            new TeachingJournalResource($journal->refresh()->load($this->with)),
            'Jurnal mengajar berhasil diperbarui',
        );
    }

    public function destroy(Request $request, TeachingJournal $journal): JsonResponse
    {
        $user = $request->user();
        $canApprove = (bool) $user?->can('academic.approve');
        $linked = $this->linkedEmployeeId($user);

        if ($linked !== null && $linked !== (int) $journal->teacher_id && ! $canApprove) {
            abort(403, 'Anda hanya boleh menghapus jurnal milik sendiri.');
        }

        if ($journal->status === TeachingJournal::STATUS_COMPLETED && ! $canApprove) {
            throw ValidationException::withMessages([
                'status' => 'Jurnal yang sudah selesai terkunci dan tidak bisa dihapus.',
            ]);
        }

        $journal->delete();

        return $this->successResponse(null, 'Jurnal mengajar berhasil dihapus');
    }

    /**
     * Tandai jurnal selesai dan catat realisasi JP.
     */
    public function complete(CompleteJournalRequest $request, TeachingJournal $journal): JsonResponse
    {
        $user = $request->user();
        $canApprove = (bool) $user?->can('academic.approve');
        $linked = $this->linkedEmployeeId($user);

        if ($linked !== null && $linked !== (int) $journal->teacher_id && ! $canApprove) {
            abort(403, 'Anda hanya boleh menyelesaikan jurnal milik sendiri.');
        }

        if ($journal->status === TeachingJournal::STATUS_COMPLETED && ! $canApprove) {
            throw ValidationException::withMessages([
                'status' => 'Jurnal ini sudah berstatus selesai.',
            ]);
        }

        if ($journal->status === TeachingJournal::STATUS_CANCELLED && ! $canApprove) {
            throw ValidationException::withMessages([
                'status' => 'Jurnal yang dibatalkan tidak bisa diselesaikan tanpa izin khusus.',
            ]);
        }

        $data = $request->validated();

        $this->assertRealizedJp(
            (int) $journal->class_id,
            (int) $journal->subject_id,
            $data['realized_jp'],
        );

        $journal->update([
            'status' => TeachingJournal::STATUS_COMPLETED,
            'realized_jp' => $data['realized_jp'],
            'student_present_count' => $data['student_present_count'] ?? $journal->student_present_count,
            'activities' => $data['activities'] ?? $journal->activities,
            'notes' => $data['notes'] ?? $journal->notes,
        ]);

        return $this->successResponse(
            new TeachingJournalResource($journal->refresh()->load($this->with)),
            'Jurnal mengajar ditandai selesai',
        );
    }

    /**
     * Jadwal hari ini milik guru yang login, ditandai mana yang belum dijurnal.
     */
    public function today(Request $request): JsonResponse
    {
        $teacherId = $this->linkedEmployeeId($request->user()) ?? $this->resolveTeacherId($request);

        if (! $teacherId) {
            throw ValidationException::withMessages([
                'teacher_id' => 'Guru tidak terdeteksi. Pilih guru terlebih dahulu.',
            ]);
        }

        $semesterId = $request->integer('semester_id') ?: Semester::query()
            ->where('is_active', true)
            ->value('id');

        if (! $semesterId) {
            throw ValidationException::withMessages([
                'semester_id' => 'Semester aktif belum ditetapkan.',
            ]);
        }

        $date = $request->query('date') ?: Carbon::today()->toDateString();
        $dayId = Carbon::parse($date)->dayOfWeekIso;

        $schedules = Schedule::query()
            ->with(['schoolClass', 'subject', 'timeSlot'])
            ->where('employee_id', $teacherId)
            ->where('semester_id', $semesterId)
            ->where('day_id', $dayId)
            ->orderBy('start_time')
            ->get();

        $journals = TeachingJournal::query()
            ->with($this->with)
            ->where('teacher_id', $teacherId)
            ->whereDate('date', $date)
            ->get();

        $bySchedule = $journals->whereNotNull('schedule_id')->keyBy('schedule_id');
        $bySlot = $journals->whereNotNull('time_slot_id')->keyBy('time_slot_id');

        $items = $schedules->map(function (Schedule $schedule) use ($bySchedule, $bySlot) {
            $journal = $bySchedule->get($schedule->id)
                ?? ($schedule->time_slot_id ? $bySlot->get($schedule->time_slot_id) : null);

            return [
                'schedule_id' => $schedule->id,
                'class_id' => $schedule->school_class_id,
                'subject_id' => $schedule->subject_id,
                'time_slot_id' => $schedule->time_slot_id,
                'start_time' => $schedule->start_time ? substr($schedule->start_time, 0, 5) : null,
                'end_time' => $schedule->end_time ? substr($schedule->end_time, 0, 5) : null,
                'school_class' => $schedule->schoolClass ? [
                    'id' => $schedule->schoolClass->id,
                    'name' => $schedule->schoolClass->name,
                    'code' => $schedule->schoolClass->code,
                ] : null,
                'subject' => $schedule->subject ? [
                    'id' => $schedule->subject->id,
                    'code' => $schedule->subject->code,
                    'name' => $schedule->subject->name,
                ] : null,
                'time_slot' => $schedule->timeSlot ? [
                    'id' => $schedule->timeSlot->id,
                    'label' => $schedule->timeSlot->label,
                    'start_time' => substr($schedule->timeSlot->start_time, 0, 5),
                    'end_time' => substr($schedule->timeSlot->end_time, 0, 5),
                ] : null,
                'has_journal' => $journal !== null,
                'journal' => $journal ? new TeachingJournalResource($journal) : null,
            ];
        })->values();

        return $this->successResponse([
            'date' => $date,
            'day_id' => $dayId,
            'teacher_id' => (int) $teacherId,
            'semester_id' => (int) $semesterId,
            'total' => $items->count(),
            'journaled' => $items->where('has_journal', true)->count(),
            'pending' => $items->where('has_journal', false)->count(),
            'items' => $items,
        ], 'Jadwal mengajar hari ini berhasil diambil');
    }

    /**
     * Rekap JP terealisasi vs JP terjadwal per guru pada satu semester.
     */
    public function recap(Request $request): JsonResponse
    {
        $data = $request->validate([
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'teacher_id' => ['nullable', 'integer', 'exists:employees,id'],
        ], [], [
            'semester_id' => 'semester',
            'teacher_id' => 'guru',
        ]);

        $semesterId = (int) $data['semester_id'];

        $teachersQuery = \App\Models\Employee::query()
            ->where('is_teacher', true)
            ->orderBy('full_name');

        if (! empty($data['teacher_id'])) {
            $teachersQuery->whereKey($data['teacher_id']);
        }

        $items = $teachersQuery->get()->map(function ($teacher) use ($semesterId) {
            // JP terjadwal = jumlah jam mengajar mingguan guru pada semester ini.
            $scheduledJp = Schedule::query()
                ->where('employee_id', $teacher->id)
                ->where('semester_id', $semesterId)
                ->count();

            $journalQuery = TeachingJournal::query()
                ->where('teacher_id', $teacher->id)
                ->where('semester_id', $semesterId)
                ->realized();

            $realizedJp = (int) $journalQuery->clone()->sum('realized_jp');
            $journalCount = $journalQuery->clone()->count();
            $completedCount = $journalQuery->clone()
                ->where('status', TeachingJournal::STATUS_COMPLETED)
                ->count();

            return [
                'teacher_id' => $teacher->id,
                'teacher' => [
                    'id' => $teacher->id,
                    'nip' => $teacher->nip,
                    'full_name' => $teacher->full_name,
                ],
                'scheduled_jp' => $scheduledJp,
                'realized_jp' => $realizedJp,
                'journal_count' => $journalCount,
                'completed_count' => $completedCount,
            ];
        })->values();

        return $this->successResponse([
            'semester_id' => $semesterId,
            'items' => $items,
            'totals' => [
                'teachers' => $items->count(),
                'scheduled_jp' => (int) $items->sum('scheduled_jp'),
                'realized_jp' => (int) $items->sum('realized_jp'),
                'journal_count' => (int) $items->sum('journal_count'),
                'completed_count' => (int) $items->sum('completed_count'),
            ],
        ], 'Rekap jurnal mengajar berhasil diambil');
    }

    // -----------------------------------------------------------------
    // Pemeriksaan aturan bisnis
    // -----------------------------------------------------------------

    /**
     * Bila jurnal dikaitkan ke jadwal, jam pelajaran diambil dari jadwal itu.
     *
     * @param  array<string, mixed>  $data
     */
    protected function applyScheduleDefaults(array &$data): void
    {
        if (empty($data['schedule_id'])) {
            return;
        }

        $schedule = Schedule::find($data['schedule_id']);

        if ($schedule && empty($data['time_slot_id'])) {
            $data['time_slot_id'] = $schedule->time_slot_id;
        }
    }

    protected function assertTeachesSubject(int $classId, int $subjectId, int $teacherId): void
    {
        $teaches = ClassSubject::query()
            ->where('school_class_id', $classId)
            ->where('subject_id', $subjectId)
            ->where('employee_id', $teacherId)
            ->exists();

        if (! $teaches) {
            throw ValidationException::withMessages([
                'subject_id' => 'Guru ini tidak mengampu mapel tersebut di kelas yang dipilih.',
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
                'date' => 'Tanggal jurnal harus berada di dalam rentang semester yang dipilih.',
            ]);
        }
    }

    protected function assertRealizedJp(int $classId, int $subjectId, mixed $realized): void
    {
        if ($realized === null || $realized === '') {
            return;
        }

        $allocation = $this->allocationFor($classId, $subjectId);

        if ($allocation !== null && (int) $realized > $allocation) {
            throw ValidationException::withMessages([
                'realized_jp' => "JP terealisasi tidak boleh melebihi alokasi {$allocation} JP untuk mapel ini di kelas tersebut.",
            ]);
        }
    }

    protected function assertSlotFree(int $teacherId, ?string $date, mixed $timeSlotId, ?int $ignoreId = null): void
    {
        if (empty($timeSlotId) || ! $date) {
            return;
        }

        $exists = TeachingJournal::query()
            ->where('teacher_id', $teacherId)
            ->whereDate('date', $date)
            ->where('time_slot_id', $timeSlotId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'time_slot_id' => 'Sudah ada jurnal untuk jam pelajaran ini pada tanggal tersebut.',
            ]);
        }
    }
}
