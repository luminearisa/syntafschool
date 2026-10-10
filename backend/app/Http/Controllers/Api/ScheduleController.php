<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ScheduleChangeResource;
use App\Http\Resources\ScheduleResource;
use App\Http\Resources\TimeSlotResource;
use App\Models\ClassSubject;
use App\Models\Day;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\ScheduleChange;
use App\Models\TimeSlot;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Jadwal pelajaran.
 *
 * Aturan inti controller ini adalah **empat deteksi bentrok** yang dijalankan
 * sebelum setiap penyimpanan:
 *
 * 1. Bentrok **kelas**  — satu kelas tidak bisa dua pelajaran pada jam sama.
 * 2. Bentrok **guru**   — satu guru tidak bisa mengajar dua kelas pada jam sama.
 * 3. Bentrok **ruang**  — satu ruang tidak bisa dipakai dua kelas pada jam sama.
 * 4. **Jam terbalik**   — jam selesai harus lebih besar dari jam mulai.
 *
 * Perubahan harian (guru pengganti, pindah, batal) tidak mengubah jadwal pokok;
 * dicatat pada `schedule_changes` supaya jadwal asli tetap bisa diaudit.
 */
class ScheduleController extends BaseApiController
{
    /** Kolom yang boleh difilter lewat query string. */
    protected array $filterable = [
        'school_class_id',
        'employee_id',
        'room_id',
        'day_id',
        'semester_id',
        'subject_id',
    ];

    /** Relasi standar agar frontend tidak perlu request tambahan. */
    protected array $with = ['schoolClass', 'subject', 'employee', 'room', 'day', 'timeSlot'];

    public function index(Request $request): JsonResponse
    {
        $query = Schedule::query()->with($this->with);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $this->filterable, true)) {
                continue;
            }
            $query->where($column, $value);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('subject', fn ($s) => $s->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('employee', fn ($e) => $e->where('full_name', 'like', "%{$search}%"))
                    ->orWhereHas('schoolClass', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $query->orderBy('day_id')->orderBy('start_time');

        if ($request->boolean('all')) {
            return $this->successResponse(
                ScheduleResource::collection($query->get()),
                'Jadwal berhasil diambil',
            );
        }

        return $this->successResponse(
            ScheduleResource::collection($query->paginate((int) $request->query('per_page', 50))),
            'Jadwal berhasil diambil',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $this->assertNoConflict($data);

        $schedule = Schedule::create($data);

        return $this->createdResponse(
            new ScheduleResource($schedule->load($this->with)),
            'Jadwal berhasil ditambahkan',
        );
    }

    public function update(Request $request, Schedule $schedule): JsonResponse
    {
        $data = $this->validatePayload($request);

        $this->assertNoConflict($data, $schedule->id);

        $schedule->update($data);

        return $this->successResponse(
            new ScheduleResource($schedule->refresh()->load($this->with)),
            'Jadwal berhasil diperbarui',
        );
    }

    public function destroy(Schedule $schedule): JsonResponse
    {
        $schedule->delete();

        return $this->successResponse(null, 'Jadwal berhasil dihapus');
    }

    /**
     * Pemeriksaan bentrok tanpa menyimpan.
     *
     * Dipakai frontend untuk memberi peringatan sebelum form disubmit.
     * Selalu 200 — hasilnya ada di `data.conflicts`.
     */
    public function checkConflict(Request $request): JsonResponse
    {
        $data = $request->validate([
            'school_class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'day_id' => ['required', 'integer', 'exists:days,id'],
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'ignore_id' => ['nullable', 'integer'],
        ], [], [
            'school_class_id' => 'kelas',
            'day_id' => 'hari',
            'semester_id' => 'semester',
            'start_time' => 'jam mulai',
            'end_time' => 'jam selesai',
        ]);

        $data['start_time'] .= ':00';
        $data['end_time'] .= ':00';

        $conflicts = $this->detectConflicts($data, $request->integer('ignore_id') ?: null);

        return $this->successResponse([
            'has_conflict' => $conflicts !== [],
            'conflicts' => $conflicts,
        ], $conflicts === [] ? 'Tidak ada bentrok jadwal' : 'Ditemukan bentrok jadwal');
    }

    /**
     * Grid mingguan: baris = jam pelajaran, kolom = hari.
     *
     * Salah satu dari `school_class_id`, `employee_id`, atau `room_id` dipakai
     * sebagai sudut pandang (jadwal per kelas / per guru / per ruang).
     */
    public function grid(Request $request): JsonResponse
    {
        $data = $request->validate([
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'school_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'date' => ['nullable', 'date'],
        ], [], [
            'semester_id' => 'semester',
            'school_class_id' => 'kelas',
            'employee_id' => 'guru',
            'room_id' => 'ruangan',
        ]);

        $query = Schedule::query()
            ->with($this->with)
            ->where('semester_id', $data['semester_id']);

        foreach (['school_class_id', 'employee_id', 'room_id'] as $key) {
            if (! empty($data[$key])) {
                $query->where($key, $data[$key]);
            }
        }

        $entries = $query->orderBy('day_id')->orderBy('start_time')->get();

        // Bila tanggal dipilih, sertakan perubahan jadwal hari itu (guru pengganti,
        // pembatalan, perpindahan) agar grid menampilkan kondisi nyata.
        $changes = collect();
        if (! empty($data['date'])) {
            $changes = ScheduleChange::query()
                ->with(['substituteEmployee', 'newRoom', 'newDay'])
                ->whereIn('schedule_id', $entries->pluck('id'))
                ->whereDate('date', $data['date'])
                ->get();
        }

        return $this->successResponse([
            'days' => Day::query()->orderBy('id')->get(['id', 'name'])
                ->map(fn (Day $day) => ['id' => $day->id, 'name' => $day->name])
                ->values(),
            'slots' => TimeSlotResource::collection(
                TimeSlot::query()
                    ->where('is_active', true)
                    ->orderBy('day_id')
                    ->orderBy('slot_number')
                    ->get()
            ),
            'entries' => ScheduleResource::collection($entries),
            'changes' => ScheduleChangeResource::collection($changes),
            'date' => $data['date'] ?? null,
        ], 'Grid jadwal berhasil diambil');
    }

    // -----------------------------------------------------------------
    // Perubahan jadwal (guru pengganti / pindah / batal)
    // -----------------------------------------------------------------

    public function indexChanges(Request $request): JsonResponse
    {
        $query = ScheduleChange::query()->with([
            'substituteEmployee',
            'newRoom',
            'newDay',
            'schedule.schoolClass',
            'schedule.subject',
            'schedule.employee',
            'schedule.room',
            'schedule.day',
        ]);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, ['type', 'schedule_id'], true)) {
                continue;
            }
            $query->where($column, $value);
        }

        if ($date = $request->query('date')) {
            $query->whereDate('date', $date);
        }
        if ($from = $request->query('from')) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('date', '<=', $to);
        }
        if ($employeeId = $request->query('employee_id')) {
            $query->where('substitute_employee_id', $employeeId);
        }

        $query->orderByDesc('date')->orderByDesc('id');

        return $this->successResponse(
            ScheduleChangeResource::collection($query->limit(200)->get()),
            'Perubahan jadwal berhasil diambil',
        );
    }

    public function storeChange(Request $request, Schedule $schedule): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'type' => ['required', 'in:pengganti,pindah,batal'],
            'substitute_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'new_room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'new_day_id' => ['nullable', 'integer', 'exists:days,id'],
            'new_start_time' => ['nullable', 'date_format:H:i'],
            'new_end_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [], [
            'date' => 'tanggal',
            'type' => 'jenis perubahan',
            'substitute_employee_id' => 'guru pengganti',
            'new_room_id' => 'ruangan baru',
            'new_day_id' => 'hari baru',
            'new_start_time' => 'jam mulai baru',
            'new_end_time' => 'jam selesai baru',
            'reason' => 'alasan',
        ]);

        // Satu jadwal hanya boleh punya satu perubahan per tanggal.
        $alreadyChanged = ScheduleChange::where('schedule_id', $schedule->id)
            ->whereDate('date', $data['date'])
            ->exists();

        if ($alreadyChanged) {
            throw ValidationException::withMessages([
                'date' => 'Jadwal ini sudah punya perubahan pada tanggal tersebut. Hapus dulu perubahan sebelumnya.',
            ]);
        }

        if ($data['type'] === 'pengganti') {
            if (empty($data['substitute_employee_id'])) {
                throw ValidationException::withMessages([
                    'substitute_employee_id' => 'Guru pengganti wajib dipilih.',
                ]);
            }

            $this->assertTeacher((int) $data['substitute_employee_id']);
            $this->assertSubstituteFree($schedule, $data['date'], (int) $data['substitute_employee_id']);
        }

        if ($data['type'] === 'pindah') {
            if (empty($data['new_start_time']) || empty($data['new_end_time'])) {
                throw ValidationException::withMessages([
                    'new_start_time' => 'Jam baru wajib diisi untuk jadwal yang dipindah.',
                ]);
            }

            if ($data['new_start_time'] >= $data['new_end_time']) {
                throw ValidationException::withMessages([
                    'new_end_time' => 'Jam selesai harus lebih besar dari jam mulai.',
                ]);
            }

            $data['new_start_time'] .= ':00';
            $data['new_end_time'] .= ':00';

            $this->assertMoveFree($schedule, $data);
        }

        $data['created_by'] = $request->user()?->id;

        $change = $schedule->changes()->create($data);

        return $this->createdResponse(
            new ScheduleChangeResource($change->load(['substituteEmployee', 'newRoom', 'newDay'])),
            'Perubahan jadwal berhasil dicatat',
        );
    }

    public function destroyChange(ScheduleChange $scheduleChange): JsonResponse
    {
        $scheduleChange->delete();

        return $this->successResponse(null, 'Perubahan jadwal berhasil dibatalkan');
    }

    // -----------------------------------------------------------------
    // Validasi & deteksi bentrok
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'school_class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'day_id' => ['required', 'integer', 'exists:days,id'],
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'time_slot_id' => ['nullable', 'integer', 'exists:time_slots,id'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:150'],
        ], [], [
            'school_class_id' => 'kelas',
            'subject_id' => 'mata pelajaran',
            'employee_id' => 'guru',
            'room_id' => 'ruangan',
            'day_id' => 'hari',
            'semester_id' => 'semester',
            'time_slot_id' => 'jam pelajaran',
            'start_time' => 'jam mulai',
            'end_time' => 'jam selesai',
            'note' => 'catatan',
        ]);

        // Guru harus berstatus pengajar.
        if (! empty($data['employee_id'])) {
            $this->assertTeacher((int) $data['employee_id'], 'employee_id');
        }

        // Mapel harus sudah terdaftar pada kelas ini (menu "Mapel per Kelas").
        // Tanpa ini, jadwal bisa memuat mapel yang tidak pernah diajarkan di kelas itu.
        $registered = ClassSubject::where('school_class_id', $data['school_class_id'])
            ->where('subject_id', $data['subject_id'])
            ->exists();

        if (! $registered) {
            throw ValidationException::withMessages([
                'subject_id' => 'Mapel ini belum terdaftar di kelas tersebut. Tambahkan dulu melalui Mapel per Kelas.',
            ]);
        }

        // Jam pelajaran yang dipilih harus milik hari yang sama.
        if (! empty($data['time_slot_id'])) {
            $slot = TimeSlot::find($data['time_slot_id']);

            if ($slot && (int) $slot->day_id !== (int) $data['day_id']) {
                throw ValidationException::withMessages([
                    'time_slot_id' => 'Jam pelajaran yang dipilih bukan untuk hari tersebut.',
                ]);
            }
        }

        // Simpan dalam H:i:s agar perbandingan antar jam konsisten.
        $data['start_time'] .= ':00';
        $data['end_time'] .= ':00';

        return $data;
    }

    /**
     * Kumpulkan seluruh bentrok tanpa melempar exception.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{type: string, message: string, schedule_id?: int}>
     */
    protected function detectConflicts(array $data, ?int $ignoreId = null): array
    {
        $start = $data['start_time'];
        $end = $data['end_time'];

        // Aturan 4 — jam terbalik. Bila rentangnya tidak masuk akal, perbandingan
        // bentrok lain tidak bermakna, jadi langsung berhenti di sini.
        if ($start >= $end) {
            return [[
                'type' => 'jam',
                'message' => 'Jam selesai harus lebih besar dari jam mulai.',
            ]];
        }

        $conflicts = [];

        // Builder baru tiap pemanggilan supaya klausa antar aturan tidak tercampur.
        $base = fn () => Schedule::query()
            ->with(['subject:id,code,name', 'schoolClass:id,name,code', 'employee:id,full_name', 'room:id,name'])
            ->where('day_id', $data['day_id'])
            ->where('semester_id', $data['semester_id'])
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId));

        // Aturan 1 — bentrok kelas.
        if ($clash = $base()->where('school_class_id', $data['school_class_id'])->first()) {
            $conflicts[] = [
                'type' => 'kelas',
                'message' => sprintf(
                    'Kelas ini sudah punya %s pada jam %s–%s.',
                    $clash->subject?->name ?? 'pelajaran lain',
                    substr($clash->start_time, 0, 5),
                    substr($clash->end_time, 0, 5),
                ),
                'schedule_id' => $clash->id,
            ];
        }

        // Aturan 2 — bentrok guru.
        if (! empty($data['employee_id'])) {
            if ($clash = $base()->where('employee_id', $data['employee_id'])->first()) {
                $conflicts[] = [
                    'type' => 'guru',
                    'message' => sprintf(
                        'Guru ini sudah mengajar %s di %s pada jam %s–%s.',
                        $clash->subject?->name ?? 'mapel lain',
                        $clash->schoolClass?->name ?? 'kelas lain',
                        substr($clash->start_time, 0, 5),
                        substr($clash->end_time, 0, 5),
                    ),
                    'schedule_id' => $clash->id,
                ];
            }
        }

        // Aturan 3 — bentrok ruang.
        if (! empty($data['room_id'])) {
            if ($clash = $base()->where('room_id', $data['room_id'])->first()) {
                $conflicts[] = [
                    'type' => 'ruang',
                    'message' => sprintf(
                        'Ruang ini sudah dipakai %s (%s) pada jam %s–%s.',
                        $clash->schoolClass?->name ?? 'kelas lain',
                        $clash->subject?->name ?? 'mapel lain',
                        substr($clash->start_time, 0, 5),
                        substr($clash->end_time, 0, 5),
                    ),
                    'schedule_id' => $clash->id,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertNoConflict(array $data, ?int $ignoreId = null): void
    {
        $conflicts = $this->detectConflicts($data, $ignoreId);

        if ($conflicts === []) {
            return;
        }

        // Petakan tiap bentrok ke field form yang paling relevan agar pesan
        // validasi muncul tepat di sebelah input yang salah.
        $messages = [];

        foreach ($conflicts as $conflict) {
            $field = match ($conflict['type']) {
                'jam' => 'end_time',
                'guru' => 'employee_id',
                'ruang' => 'room_id',
                default => 'start_time',
            };

            $messages[$field][] = $conflict['message'];
        }

        throw ValidationException::withMessages($messages);
    }

    protected function assertTeacher(int $employeeId, string $field = 'substitute_employee_id'): void
    {
        $isTeacher = Employee::whereKey($employeeId)->where('is_teacher', true)->exists();

        if (! $isTeacher) {
            throw ValidationException::withMessages([
                $field => 'Guru harus pegawai yang berstatus pengajar.',
            ]);
        }
    }

    /**
     * Guru pengganti tidak boleh sedang mengajar kelas lain pada jam yang sama.
     */
    protected function assertSubstituteFree(Schedule $schedule, string $date, int $employeeId): void
    {
        // day_id memakai konvensi ISO: 1 = Senin … 7 = Minggu.
        $dayId = Carbon::parse($date)->dayOfWeekIso;

        $clash = Schedule::query()
            ->with(['subject:id,name', 'schoolClass:id,name'])
            ->where('id', '!=', $schedule->id)
            ->where('employee_id', $employeeId)
            ->where('day_id', $dayId)
            ->where('semester_id', $schedule->semester_id)
            ->where('start_time', '<', $schedule->end_time)
            ->where('end_time', '>', $schedule->start_time)
            ->first();

        if ($clash) {
            throw ValidationException::withMessages([
                'substitute_employee_id' => sprintf(
                    'Guru pengganti sudah mengajar %s di %s pada jam yang sama (%s–%s).',
                    $clash->subject?->name ?? 'mapel lain',
                    $clash->schoolClass?->name ?? 'kelas lain',
                    substr($clash->start_time, 0, 5),
                    substr($clash->end_time, 0, 5),
                ),
            ]);
        }

        // Guru yang sama mungkin sudah ditugaskan menggantikan jadwal lain pada
        // tanggal itu lewat `schedule_changes` — jadwal pokok tidak menangkapnya.
        $changeClash = ScheduleChange::query()
            ->with(['schedule.subject:id,name', 'schedule.schoolClass:id,name'])
            ->whereDate('date', $date)
            ->where('type', 'pengganti')
            ->where('substitute_employee_id', $employeeId)
            ->where('schedule_id', '!=', $schedule->id)
            ->whereHas('schedule', function ($q) use ($schedule): void {
                $q->where('start_time', '<', $schedule->end_time)
                    ->where('end_time', '>', $schedule->start_time);
            })
            ->first();

        if ($changeClash) {
            throw ValidationException::withMessages([
                'substitute_employee_id' => sprintf(
                    'Guru pengganti sudah ditugaskan menggantikan %s di %s pada tanggal tersebut (%s–%s).',
                    $changeClash->schedule?->subject?->name ?? 'mapel lain',
                    $changeClash->schedule?->schoolClass?->name ?? 'kelas lain',
                    substr((string) $changeClash->schedule?->start_time, 0, 5),
                    substr((string) $changeClash->schedule?->end_time, 0, 5),
                ),
            ]);
        }
    }

    /**
     * Jadwal yang dipindah tidak boleh bentrok dengan kelas, guru pengampu, atau
     * ruang tujuan pada hari/jam barunya — sama seperti saat menjadwalkan biasa.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertMoveFree(Schedule $schedule, array $data): void
    {
        $dayId = (int) ($data['new_day_id'] ?? $schedule->day_id);
        $start = $data['new_start_time'];
        $end = $data['new_end_time'];
        $roomId = $data['new_room_id'] ?? $schedule->room_id;

        $base = fn () => Schedule::query()
            ->with(['subject:id,name', 'schoolClass:id,name'])
            ->where('id', '!=', $schedule->id)
            ->where('day_id', $dayId)
            ->where('semester_id', $schedule->semester_id)
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start);

        if ($clash = $base()->where('school_class_id', $schedule->school_class_id)->first()) {
            throw ValidationException::withMessages([
                'new_start_time' => sprintf(
                    'Kelas ini sudah punya %s pada jam %s–%s.',
                    $clash->subject?->name ?? 'pelajaran lain',
                    substr($clash->start_time, 0, 5),
                    substr($clash->end_time, 0, 5),
                ),
            ]);
        }

        if ($schedule->employee_id && ($clash = $base()->where('employee_id', $schedule->employee_id)->first())) {
            throw ValidationException::withMessages([
                'new_start_time' => sprintf(
                    'Guru pengampu sudah mengajar %s di %s pada jam %s–%s.',
                    $clash->subject?->name ?? 'mapel lain',
                    $clash->schoolClass?->name ?? 'kelas lain',
                    substr($clash->start_time, 0, 5),
                    substr($clash->end_time, 0, 5),
                ),
            ]);
        }

        if ($roomId && ($clash = $base()->where('room_id', $roomId)->first())) {
            throw ValidationException::withMessages([
                'new_start_time' => sprintf(
                    'Ruang ini sudah dipakai %s (%s) pada jam %s–%s.',
                    $clash->schoolClass?->name ?? 'kelas lain',
                    $clash->subject?->name ?? 'mapel lain',
                    substr($clash->start_time, 0, 5),
                    substr($clash->end_time, 0, 5),
                ),
            ]);
        }

        // Jadwal lain yang juga dipindah ke tanggal & slot yang sama lewat
        // `schedule_changes` tidak terlihat pada jadwal pokok di atas.
        if ($date = $data['date'] ?? null) {
            if ($clash = $this->movedChangeClash($schedule, $date, $dayId, $start, $end, $roomId)) {
                throw ValidationException::withMessages([
                    'new_start_time' => sprintf(
                        'Jadwal %s (%s) sudah dipindah ke slot yang sama pada tanggal tersebut.',
                        $clash->schedule?->schoolClass?->name ?? 'kelas lain',
                        $clash->schedule?->subject?->name ?? 'mapel lain',
                    ),
                ]);
            }
        }
    }

    /**
     * Cari perubahan "pindah" lain pada tanggal yang sama yang slot barunya
     * bentrok untuk kelas, guru, atau ruang yang sama.
     */
    protected function movedChangeClash(
        Schedule $schedule,
        string $date,
        int $dayId,
        string $start,
        string $end,
        ?int $roomId,
    ): ?ScheduleChange {
        $changes = ScheduleChange::query()
            ->with(['schedule:id,school_class_id,employee_id,room_id,day_id,subject_id', 'schedule.schoolClass:id,name', 'schedule.subject:id,name'])
            ->whereDate('date', $date)
            ->where('type', 'pindah')
            ->where('schedule_id', '!=', $schedule->id)
            ->whereNotNull('new_start_time')
            ->whereNotNull('new_end_time')
            ->where('new_start_time', '<', $end)
            ->where('new_end_time', '>', $start)
            ->get();

        foreach ($changes as $change) {
            $other = $change->schedule;

            if ($other === null) {
                continue;
            }

            $otherDay = (int) ($change->new_day_id ?? $other->day_id);

            if ($otherDay !== $dayId) {
                continue;
            }

            $otherRoom = (int) ($change->new_room_id ?? $other->room_id);

            $sameClass = (int) $other->school_class_id === (int) $schedule->school_class_id;
            $sameTeacher = $schedule->employee_id && (int) $other->employee_id === (int) $schedule->employee_id;
            $sameRoom = $roomId && $otherRoom === (int) $roomId;

            if ($sameClass || $sameTeacher || $sameRoom) {
                return $change;
            }
        }

        return null;
    }
}
