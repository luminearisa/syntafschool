<?php

namespace App\Services\Boarding;

use App\Models\BoardingBed;
use App\Models\BoardingDormitory;
use App\Models\BoardingPlacement;
use App\Models\BoardingRoom;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Penempatan & mutasi santri pada struktur asrama (§7, §9, §11).
 *
 * Kelas ini adalah SATU-SATUNYA pintu tulis ke `boarding_placements`: aturan
 * "satu penempatan aktif per santri", "satu santri per tempat tidur",
 * "occupancy <= capacity", dan kecocokan gender ditegakkan di sini dengan
 * `DB::transaction` + `lockForUpdate`, bukan di controller dan bukan lewat
 * constraint tabel (SQLite dev/test tidak mendukung `ALTER ... ADD CONSTRAINT`).
 *
 * Empat operasi:
 * - `assign()`   → penempatan baru; kamar (dan opsional satu bed) dipilih,
 *   seluruh penjagaan dijalankan sebelum baris ditulis;
 * - `transfer()` → mutasi: placement lama DITUTUP (`end_reason` auto-detect
 *   `transfer_dorm`/`transfer_room`/`transfer_bed` + `replaced_by_placement_id`),
 *   placement baru lahir aktif, bed lama dibebaskan — riwayat tidak pernah hilang;
 * - `end()`      → penutupan tanpa penggantinya (lulus/keluar/batal);
 * - `roomOccupancy()` → ringkasan hunian satu kamar.
 *
 * Daftar placement (`query()`/`historyQuery()`) ikut di sini karena filter
 * scope pengasuh (`BoardingScopeService`) bagian dari aturan baca modul:
 * `restricted()` user hanya melihat santri binaannya.
 *
 * Setiap mutasi meninggalkan jejak audit ber-event eksplisit huruf kapital
 * (`BOARDING_PLACEMENT_ASSIGNED/_TRANSFERRED/_ENDED`) pada log `boarding`
 * (§57, §73).
 */
class BoardingPlacementService
{
    /** Alasan yang boleh dipakai `end()` — penutupan manual, bukan hasil mutasi. */
    public const CLOSABLE_END_REASONS = [
        BoardingPlacement::END_REASON_GRADUATED,
        BoardingPlacement::END_REASON_DROPPED_OUT,
        BoardingPlacement::END_REASON_CANCELLED,
        BoardingPlacement::END_REASON_OTHER,
    ];

    public function __construct(
        private readonly BoardingScopeService $scope,
    ) {
    }

    // =====================================================================
    // Baca
    // =====================================================================

    /**
     * Daftar penempatan + filter area/status/santri/pencarian dan potongan
     * scope pengasuh. `null` dari `studentIdsFor()` berarti "jangan disaring";
     * `[]` berarti "tidak ada yang boleh dilihat" — keduanya diperlakukan beda.
     */
    public function query(Request $request, ?User $actor = null): Builder
    {
        $builder = BoardingPlacement::query()
            ->with($this->eagerLoads())
            ->orderByDesc('id');

        $builder
            ->when($request->filled('dormitory_id'), fn (Builder $q) => $q->where('dormitory_id', (int) $request->input('dormitory_id')))
            ->when($request->filled('building_id'), fn (Builder $q) => $q->where('building_id', (int) $request->input('building_id')))
            ->when($request->filled('floor_id'), fn (Builder $q) => $q->where('floor_id', (int) $request->input('floor_id')))
            ->when($request->filled('room_id'), fn (Builder $q) => $q->where('room_id', (int) $request->input('room_id')))
            ->when($request->filled('bed_id'), fn (Builder $q) => $q->where('bed_id', (int) $request->input('bed_id')))
            ->when($request->filled('student_id'), fn (Builder $q) => $q->where('student_id', (int) $request->input('student_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('start_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('start_date', '<=', $request->date('to')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));

                $q->whereHas('student', fn (Builder $s) => $s
                    ->where('full_name', 'like', "%$term%")
                    ->orWhere('nis', 'like', "%$term%"));
            });

        $allowed = $this->scope->studentIdsFor($actor);

        if ($allowed !== null) {
            $builder->whereIn('student_id', $allowed);
        }

        return $builder;
    }

    /** Riwayat lengkap satu santri, terbaru lebih dulu (status aktif maupun lama). */
    public function historyQuery(Student $student): Builder
    {
        return BoardingPlacement::query()
            ->with($this->eagerLoads())
            ->where('student_id', $student->id)
            ->orderByDesc('start_date')
            ->orderByDesc('id');
    }

    /** Satu baris lengkap siap tampil (relasi dimuat, tanpa N+1). */
    public function withDetails(BoardingPlacement $placement): BoardingPlacement
    {
        return $placement->load($this->eagerLoads());
    }

    /** @return list<string> */
    public function eagerLoads(): array
    {
        return [
            'student:id,full_name,nis,gender_id',
            'student.gender:id,code,name',
            'dormitory:id,code,name,gender',
            'building:id,code,name',
            'floor:id,building_id,floor_number,name',
            'room:id,floor_id,dormitory_id,code,name,capacity,gender,room_type,status',
            'bed:id,room_id,bed_number,label,status',
            'assignedBy:id,name',
        ];
    }

    // =====================================================================
    // Tulis — penempatan baru
    // =====================================================================

    /**
     * Tempatkan santri pada satu kamar (opsional satu tempat tidur).
     *
     * @param  array<string, mixed>  $data  student_id, room_id, bed_id?, floor_id?,
     *                                      building_id?, dormitory_id?, start_date?, notes?
     */
    public function assign(array $data, User $actor): BoardingPlacement
    {
        /** @var BoardingPlacement $placement */
        $placement = DB::transaction(function () use ($data, $actor): BoardingPlacement {
            $student = $this->locateStudent($data['student_id'] ?? null);
            $this->assertStudentActive($student);

            $room = $this->lockRoom($data['room_id'] ?? null);
            $this->assertRoomChain($room, $data);
            $this->assertRoomGender($room, $student);
            $this->assertStudentIsFree($student, null);

            $startDate = $this->resolveDate($data['start_date'] ?? null, Carbon::today(), 'start_date');

            $bed = null;

            if (filled($data['bed_id'] ?? null)) {
                $bed = $this->lockBed($data['bed_id'], $room);
                $this->assertBedUsable($bed);
            } else {
                $this->assertRoomHasRoom($room, null);
            }

            $placement = BoardingPlacement::create([
                'student_id' => $student->id,
                'dormitory_id' => $room->dormitory_id,
                'building_id' => $room->floor->building_id,
                'floor_id' => $room->floor_id,
                'room_id' => $room->id,
                'bed_id' => $bed?->id,
                'start_date' => $startDate->toDateString(),
                'status' => BoardingPlacement::STATUS_ACTIVE,
                'notes' => $this->nullableText($data['notes'] ?? null),
                'assigned_by' => $actor->id,
            ]);

            if ($bed !== null) {
                $bed->forceFill(['status' => BoardingBed::STATUS_OCCUPIED])->save();
            }

            return $placement;
        });

        activity('boarding')
            ->event('BOARDING_PLACEMENT_ASSIGNED')
            ->performedOn($placement)
            ->causedBy($actor)
            ->withProperties([
                'student_id' => (int) $placement->student_id,
                'student_name' => $placement->student?->full_name,
                'placement_id' => (int) $placement->id,
                'dormitory_id' => (int) $placement->dormitory_id,
                'room_id' => (int) $placement->room_id,
                'room_code' => $placement->room?->code,
                'bed_id' => $placement->bed_id === null ? null : (int) $placement->bed_id,
                'bed_number' => $placement->bed?->bed_number,
                'start_date' => $placement->start_date?->toDateString(),
            ])
            ->log(sprintf(
                'Santri %s ditempatkan di %s oleh %s.',
                (string) $placement->student?->full_name,
                $placement->label(),
                (string) $actor->name,
            ));

        return $this->withDetails($placement);
    }

    /**
     * Tempatkan BANYAK santri sekaligus ke satu kamar (§9, §10).
     *
     * Seluruh penempatan dijalankan dalam SATU transaksi bersarang: bila salah
     * satu santri ditolak (mis. masih punya placement aktif, gender tidak
     * cocok, atau kapasitas kamar habis di tengah proses), seluruh batch
     * dibatalkan — tidak ada penempatan "setengah jadi". Setiap santri tetap
     * melewati penjagaan penuh di `assign()` sehingga aturan tidak dilonggarkan
     * demi kemudahan massal.
     *
     * Memilih satu `bed_id` spesifik hanya sah untuk satu santri; untuk batch
     * santri akan disebar tanpa bed tertentu (kapasitas kamar tetap dijaga).
     *
     * @param  array<int, mixed>  $studentIds
     * @param  array<string, mixed>  $data  room_id, bed_id?, floor_id?, building_id?,
     *                                      dormitory_id?, start_date?, notes?
     * @return list<BoardingPlacement>
     */
    public function assignMany(array $studentIds, array $data, User $actor): array
    {
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $studentIds)));

        if ($ids === []) {
            throw ValidationException::withMessages([
                'student_ids' => 'Pilih minimal satu santri untuk ditempatkan.',
            ]);
        }

        if (count($ids) > 1 && filled($data['bed_id'] ?? null)) {
            throw ValidationException::withMessages([
                'bed_id' => 'Tempat tidur tertentu hanya dapat dipilih bila menempatkan satu santri.',
            ]);
        }

        /** @var list<BoardingPlacement> $placements */
        $placements = DB::transaction(function () use ($ids, $data, $actor): array {
            $created = [];

            foreach ($ids as $id) {
                $created[] = $this->assign(['student_id' => $id] + $data, $actor);
            }

            return $created;
        });

        return array_map(fn (BoardingPlacement $p): BoardingPlacement => $this->withDetails($p), $placements);
    }

    // =====================================================================
    // Tulis — mutasi (§11)
    // =====================================================================

    /**
     * Pindahkan santri ke kamar/tempat tidur lain.
     *
     * Placement lama TIDAK dihapus: ditutup dengan `end_reason` hasil deteksi
     * otomatis (beda asrama → `transfer_dorm`, beda kamar → `transfer_room`,
     * hanya beda kasur → `transfer_bed`) dan ditautkan ke placement baru lewat
     * `replaced_by_placement_id`, sehingga rantai riwayat tetap terbaca.
     *
     * @param  array<string, mixed>  $data  room_id, bed_id?, reason, effective_date?
     */
    public function transfer(BoardingPlacement $placement, array $data, User $actor): BoardingPlacement
    {
        /** @var BoardingPlacement $fresh */
        $fresh = DB::transaction(function () use ($placement, $data, $actor): BoardingPlacement {
            $old = BoardingPlacement::query()->whereKey($placement->id)->lockForUpdate()->firstOrFail();

            if (! $old->isActive()) {
                throw ValidationException::withMessages([
                    'placement' => 'Penempatan ini sudah ditutup, sehingga tidak dapat dimutasi lagi. '
                        .'Buat penempatan baru bila santri kembali masuk asrama.',
                ]);
            }

            $student = $this->locateStudent($old->student_id);

            $room = $this->lockRoom($data['room_id'] ?? null);
            $this->assertRoomChain($room, $data);
            $this->assertRoomGender($room, $student);

            $effective = $this->resolveDate($data['effective_date'] ?? null, Carbon::today(), 'effective_date');

            if ($effective->lt($old->start_date->copy()->startOfDay())) {
                throw ValidationException::withMessages([
                    'effective_date' => 'Tanggal mutasi tidak boleh sebelum tanggal mulai penempatan saat ini '
                        .'('.$old->start_date->toDateString().')'.'.'
                ]);
            }

            $newBed = null;

            if (filled($data['bed_id'] ?? null)) {
                $newBed = $this->lockBed($data['bed_id'], $room);

                if ($old->bed_id !== null && (int) $newBed->id === (int) $old->bed_id) {
                    throw ValidationException::withMessages([
                        'bed_id' => 'Tempat tidur tujuan sama dengan tempat tidur sekarang — tidak ada yang perlu dimutasi.',
                    ]);
                }

                $this->assertBedUsable($newBed);
            } else {
                $this->assertRoomHasRoom($room, $old);
            }

            if ($old->bed_id === null && $newBed === null && (int) $old->room_id === (int) $room->id) {
                throw ValidationException::withMessages([
                    'room_id' => 'Penempatan baru sama persis dengan penempatan saat ini. Pilih kamar atau tempat tidur yang berbeda.',
                ]);
            }

            $reason = $this->detectTransferReason($old, $room);

            $new = BoardingPlacement::create([
                'student_id' => $student->id,
                'dormitory_id' => $room->dormitory_id,
                'building_id' => $room->floor->building_id,
                'floor_id' => $room->floor_id,
                'room_id' => $room->id,
                'bed_id' => $newBed?->id,
                'start_date' => $effective->toDateString(),
                'status' => BoardingPlacement::STATUS_ACTIVE,
                'notes' => $this->nullableText($data['reason'] ?? null) ?? 'Mutasi tanpa keterangan.',
                'assigned_by' => $actor->id,
            ]);

            $old->forceFill([
                'status' => BoardingPlacement::STATUS_INACTIVE,
                'end_date' => $effective->toDateString(),
                'end_reason' => $reason,
                'replaced_by_placement_id' => $new->id,
            ])->save();

            if ($old->bed_id !== null) {
                $oldBed = BoardingBed::query()->whereKey($old->bed_id)->lockForUpdate()->first();

                // Jangan bebaskan kasur yang justru jadi tujuan mutasi (kasus
                // same-bed sudah ditolak di atas, tetapi guard ini bertahan
                // untuk data yang dirapikan manual di luar API).
                if ($oldBed !== null && ($newBed === null || (int) $oldBed->id !== (int) $newBed->id)) {
                    $oldBed->forceFill(['status' => BoardingBed::STATUS_AVAILABLE])->save();
                }
            }

            if ($newBed !== null) {
                $newBed->forceFill(['status' => BoardingBed::STATUS_OCCUPIED])->save();
            }

            return $new;
        });

        $fresh = $this->withDetails($fresh);

        activity('boarding')
            ->event('BOARDING_PLACEMENT_TRANSFERRED')
            ->performedOn($fresh)
            ->causedBy($actor)
            ->withProperties([
                'student_id' => (int) $fresh->student_id,
                'student_name' => $fresh->student?->full_name,
                'old_placement_id' => (int) $placement->id,
                'new_placement_id' => (int) $fresh->id,
                'from' => $placement->label(),
                'to' => $fresh->label(),
                'end_reason' => $placement->fresh()->end_reason,
                'effective_date' => $fresh->start_date?->toDateString(),
                'reason' => $this->nullableText($data['reason'] ?? null),
            ])
            ->log(sprintf(
                'Santri %s dimutasi dari %s ke %s oleh %s.',
                (string) $fresh->student?->full_name,
                $placement->label(),
                $fresh->label(),
                (string) $actor->name,
            ));

        return $fresh;
    }

    // =====================================================================
    // Tulis — penutupan
    // =====================================================================

    /**
     * Akhiri penempatan (lulus, keluar, dibatalkan) — kamar dibebaskan,
     * riwayat tetap utuh.
     *
     * @param  array<string, mixed>  $data  reason (non-transfer), end_date?, notes?
     */
    public function end(BoardingPlacement $placement, array $data, User $actor): BoardingPlacement
    {
        /** @var BoardingPlacement $closed */
        $closed = DB::transaction(function () use ($placement, $data, $actor): BoardingPlacement {
            $locked = BoardingPlacement::query()->whereKey($placement->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'placement' => 'Penempatan ini sudah ditutup pada '
                        .($locked->end_date?->toDateString() ?? '-')
                        .', sehingga tidak dapat diakhiri dua kali.',
                ]);
            }

            $reason = trim((string) ($data['reason'] ?? ''));

            if (! in_array($reason, self::CLOSABLE_END_REASONS, true)) {
                throw ValidationException::withMessages([
                    'reason' => 'Alasan penutupan harus salah satu dari: '
                        .implode(', ', self::CLOSABLE_END_REASONS)
                        .'. Alasan transfer hanya dibuat lewat mekanisme mutasi.',
                ]);
            }

            $endDate = $this->resolveDate($data['end_date'] ?? null, Carbon::today(), 'end_date');

            if ($endDate->lt($locked->start_date->copy()->startOfDay())) {
                throw ValidationException::withMessages([
                    'end_date' => 'Tanggal akhir tidak boleh sebelum tanggal mulai penempatan '
                        .'('.$locked->start_date->toDateString().')'.'.'
                ]);
            }

            $notes = $this->nullableText($data['notes'] ?? null);

            $locked->forceFill(array_filter([
                'status' => BoardingPlacement::STATUS_INACTIVE,
                'end_date' => $endDate->toDateString(),
                'end_reason' => $reason,
                'notes' => $notes,
            ], static fn (mixed $value): bool => $value !== null))->save();

            if ($locked->bed_id !== null) {
                $bed = BoardingBed::query()->whereKey($locked->bed_id)->lockForUpdate()->first();

                // Hanya lepas kasur bila memang penempatan inilah penghuninya.
                $stillMine = BoardingPlacement::query()
                    ->where('bed_id', $locked->bed_id)
                    ->where('status', BoardingPlacement::STATUS_ACTIVE)
                    ->whereKeyNot($locked->id)
                    ->exists();

                if ($bed !== null && ! $stillMine) {
                    $bed->forceFill(['status' => BoardingBed::STATUS_AVAILABLE])->save();
                }
            }

            return $locked;
        });

        $closed = $this->withDetails($closed);

        activity('boarding')
            ->event('BOARDING_PLACEMENT_ENDED')
            ->performedOn($closed)
            ->causedBy($actor)
            ->withProperties([
                'student_id' => (int) $closed->student_id,
                'student_name' => $closed->student?->full_name,
                'placement_id' => (int) $closed->id,
                'location' => $closed->label(),
                'end_date' => $closed->end_date?->toDateString(),
                'end_reason' => $closed->end_reason,
            ])
            ->log(sprintf(
                'Penempatan %s di %s diakhiri (%s) oleh %s.',
                (string) $closed->student?->full_name,
                $closed->label(),
                BoardingPlacement::END_REASON_LABELS[$closed->end_reason] ?? (string) $closed->end_reason,
                (string) $actor->name,
            ));

        return $closed;
    }

    // =====================================================================
    // Baca — okupansi
    // =====================================================================

    /**
     * Ringkasan hunian satu kamar: penghuni aktif vs kapasitas, plus kondisi
     * tempat tidur (kolom `status` bed menggambarkan ketersediaan fisik, §5).
     *
     * @return array<string, mixed>
     */
    public function roomOccupancy(BoardingRoom $room): array
    {
        $occupied = $room->placements()->where('status', BoardingPlacement::STATUS_ACTIVE)->count();

        return [
            'room_id' => (int) $room->id,
            'room_code' => $room->code,
            'room_name' => $room->name,
            'capacity' => (int) $room->capacity,
            'occupied' => $occupied,
            'remaining' => max(0, (int) $room->capacity - $occupied),
            'is_full' => $occupied >= (int) $room->capacity,
            'beds_total' => $room->beds()->count(),
            'beds_available' => $room->beds()->where('status', BoardingBed::STATUS_AVAILABLE)->count(),
            'beds_occupied' => $room->beds()->where('status', BoardingBed::STATUS_OCCUPIED)->count(),
            'beds_unusable' => $room->beds()
                ->whereIn('status', [BoardingBed::STATUS_MAINTENANCE, BoardingBed::STATUS_INACTIVE])
                ->count(),
        ];
    }

    // =====================================================================
    // Internal — penjaga aturan (dibaca ulang di dalam transaksi + baris terkunci)
    // =====================================================================

    private function locateStudent(mixed $studentId): Student
    {
        $student = $studentId === null || $studentId === ''
            ? null
            : Student::query()->find((int) $studentId);

        if ($student === null) {
            throw ValidationException::withMessages(['student_id' => 'Santri tidak ditemukan.']);
        }

        return $student;
    }

    private function assertStudentActive(Student $student): void
    {
        if (! (bool) $student->is_active) {
            throw ValidationException::withMessages([
                'student_id' => "Santri {$student->full_name} tidak berstatus aktif, sehingga tidak dapat ditempatkan di asrama.",
            ]);
        }
    }

    /** @throws ValidationException 422 — kamar wajib ada dan barisnya terkunci. */
    private function lockRoom(mixed $roomId): BoardingRoom
    {
        if ($roomId === null || $roomId === '') {
            throw ValidationException::withMessages(['room_id' => 'Kamar wajib dipilih.']);
        }

        /** @var BoardingRoom|null $room */
        $room = BoardingRoom::query()->whereKey((int) $roomId)->lockForUpdate()->first();

        if ($room === null) {
            throw ValidationException::withMessages(['room_id' => 'Kamar tidak ditemukan.']);
        }

        if (! $room->isActive()) {
            $label = BoardingRoom::STATUS_LABELS[$room->status] ?? $room->status;

            throw ValidationException::withMessages([
                'room_id' => "Kamar {$room->code} sedang $label, sehingga belum dapat dihuni. "
                    .'Pulihkan status kamar menjadi aktif lebih dulu.',
            ]);
        }

        // Rantai induk dibutuhkan untuk cek gedung + asrama; `with` di atas
        // tidak berjalan saat baris dikunci, jadi dimuat eksplisit di sini.
        $room->loadMissing('floor.building', 'dormitory');

        return $room;
    }

    /** Konsistensi input klien dengan rantai kamar → lantai → gedung → asrama. */
    private function assertRoomChain(BoardingRoom $room, array $data): void
    {
        $floorId = $data['floor_id'] ?? null;
        $buildingId = $data['building_id'] ?? null;
        $dormitoryId = $data['dormitory_id'] ?? null;

        if (filled($floorId) && (int) $floorId !== (int) $room->floor_id) {
            throw ValidationException::withMessages([
                'floor_id' => "Lantai yang dipilih bukan induk kamar {$room->code} — pindah kamar cukup melalui `room_id`.",
            ]);
        }

        if (filled($buildingId) && (int) $buildingId !== (int) $room->floor?->building_id) {
            throw ValidationException::withMessages([
                'building_id' => "Gedung yang dipilih bukan induk kamar {$room->code}.",
            ]);
        }

        if (filled($dormitoryId) && (int) $dormitoryId !== (int) $room->dormitory_id) {
            throw ValidationException::withMessages([
                'dormitory_id' => "Asrama yang dipilih tidak sesuai dengan lokasi kamar {$room->code}.",
            ]);
        }
    }

    /**
     * Kecocokan gender: santri (`genders.code` L/P) terhadap kamar DAN asrama;
     * `mixed` menerima semua (§5, §6).
     */
    private function assertRoomGender(BoardingRoom $room, Student $student): void
    {
        $genderCode = $student->gender?->code;

        if (! $room->acceptsGender($genderCode)) {
            throw ValidationException::withMessages([
                'room_id' => "Kamar {$room->code} khusus ".BoardingRoom::GENDER_LABELS[$room->gender]
                    .' sedangkan santri '.$this->studentGenderLabel($student).'. Pindahkan ke kamar yang sesuai.',
            ]);
        }

        $room->loadMissing('dormitory');

        if ($room->dormitory !== null && ! $room->dormitory->acceptsGender($genderCode)) {
            throw ValidationException::withMessages([
                'dormitory_id' => 'Asrama '.$room->dormitory->name.' khusus '
                    .(BoardingDormitory::GENDER_LABELS[$room->dormitory->gender] ?? $room->dormitory->gender)
                    .' sedangkan santri '.$this->studentGenderLabel($student).'.',
            ]);
        }
    }

    private function studentGenderLabel(Student $student): string
    {
        $student->loadMissing('gender');

        return $student->gender === null
            ? 'belum memiliki jenis kelamin pada data induk'
            : "berjenis kelamin {$student->gender->name}";
    }

    /** Satu santri hanya boleh punya SATU penempatan aktif (§9). */
    private function assertStudentIsFree(Student $student, ?BoardingPlacement $ignoring): void
    {
        /** @var BoardingPlacement|null $existing */
        $existing = BoardingPlacement::query()
            ->where('student_id', $student->id)
            ->where('status', BoardingPlacement::STATUS_ACTIVE)
            ->when($ignoring !== null, fn (Builder $q) => $q->whereKeyNot($ignoring->id))
            ->latest('id')
            ->first();

        if ($existing === null) {
            return;
        }

        $existing->loadMissing('dormitory', 'room', 'bed');

        throw ValidationException::withMessages([
            'student_id' => "Santri {$student->full_name} masih aktif menempati {$existing->label()}. "
                .'Gunakan mekanisme mutasi (transfer) untuk memindahkannya, atau akhiri penempatan lama lebih dulu.',
        ]);
    }

    /** Kapasitas kamar: penghuni aktif < capacity (§7). */
    private function assertRoomHasRoom(BoardingRoom $room, ?BoardingPlacement $ignoring): void
    {
        $occupied = BoardingPlacement::query()
            ->where('room_id', $room->id)
            ->where('status', BoardingPlacement::STATUS_ACTIVE)
            ->when($ignoring !== null, fn (Builder $q) => $q->whereKeyNot($ignoring->id))
            ->count();

        if ($occupied >= (int) $room->capacity) {
            throw ValidationException::withMessages([
                'room_id' => "Kamar {$room->code} sudah penuh ({$occupied}/{$room->capacity} penghuni aktif). "
                    .'Pilih kamar lain atau tambah kapasitas kamar.',
            ]);
        }
    }

    private function lockBed(mixed $bedId, BoardingRoom $room): BoardingBed
    {
        /** @var BoardingBed|null $bed */
        $bed = BoardingBed::query()->whereKey((int) $bedId)->lockForUpdate()->first();

        if ($bed === null) {
            throw ValidationException::withMessages(['bed_id' => 'Tempat tidur tidak ditemukan.']);
        }

        if ((int) $bed->room_id !== (int) $room->id) {
            throw ValidationException::withMessages([
                'bed_id' => "Tempat tidur {$bed->bed_number} bukan bagian dari kamar {$room->code}.",
            ]);
        }

        $bed->setRelation('room', $room);

        return $bed;
    }

    /** Bed hanya boleh diterima bila `available` DAN tidak ada penghuni aktif lain. */
    private function assertBedUsable(BoardingBed $bed): void
    {
        $label = match ($bed->status) {
            BoardingBed::STATUS_OCCUPIED => 'sedang terisi',
            BoardingBed::STATUS_MAINTENANCE => 'sedang dalam perbaikan',
            BoardingBed::STATUS_INACTIVE => 'sedang dinonaktifkan',
            default => (string) $bed->status,
        };

        if ($bed->status !== BoardingBed::STATUS_AVAILABLE) {
            throw ValidationException::withMessages([
                'bed_id' => "Tempat tidur {$bed->bed_number} $label, sehingga tidak dapat ditempatkan penghuni baru.",
            ]);
        }

        $hasActivePlacement = BoardingPlacement::query()
            ->where('bed_id', $bed->id)
            ->where('status', BoardingPlacement::STATUS_ACTIVE)
            ->exists();

        if ($hasActivePlacement) {
            throw ValidationException::withMessages([
                'bed_id' => "Tempat tidur {$bed->bed_number} masih tercatat berpenghuni aktif. "
                    .'Rapikan penempatan sebelumnya lebih dulu.',
            ]);
        }
    }

    /** Deteksi jenis mutasi: beda asrama → dorm, beda kamar → room, sisanya → bed (§11). */
    private function detectTransferReason(BoardingPlacement $old, BoardingRoom $room): string
    {
        if ((int) $old->dormitory_id !== (int) $room->dormitory_id) {
            return BoardingPlacement::END_REASON_TRANSFER_DORM;
        }

        if ((int) $old->room_id !== (int) $room->id) {
            return BoardingPlacement::END_REASON_TRANSFER_ROOM;
        }

        return BoardingPlacement::END_REASON_TRANSFER_BED;
    }

    private function resolveDate(mixed $value, Carbon $fallback, string $field): Carbon
    {
        if (blank($value)) {
            return $fallback->copy()->startOfDay();
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $field => 'Format tanggal tidak dikenali (gunakan YYYY-MM-DD).',
            ]);
        }
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, 500, '');
    }
}
