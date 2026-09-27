<?php

namespace App\Services\Boarding;

use App\Models\BoardingBed;
use App\Models\BoardingFloor;
use App\Models\BoardingHealthRecord;
use App\Models\BoardingPlacement;
use App\Models\BoardingRoom;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kesehatan asrama (§39) + ruang isolasi.
 *
 * ## Batasnya sederhana: ini bukan rekam medis
 *
 * `boarding_health_records` hanya memuat gejala singkat, tanggal, tindakan, dan
 * status alur. Diagnosa, resep, dan riwayat pengobatan milik unit kesehatan
 * sekolah/RS — menyimpannya di modul asrama akan menciptakan kewajiban kerahasiaan
 * medis yang tidak bisa dipenuhi modul ini (§39).
 *
 * ## Alur status (§39)
 *
 *   reported ──► being_treated ──► recovered
 *      │              │
 *      └──────────────┴──────────► referred  (wajib ada `action`)
 *
 * `recovered` dan `referred` menutup catatan, sehingga santri tidak lagi dihitung
 * sakit oleh `BoardingStatusService` (§42). `referred` menuntut kolom `action`
 * terisi: "dirujuk ke mana, oleh siapa" adalah satu-satunya jejak yang membuat
 * perpindahan tanggung jawab bisa dipertanggungjawabkan.
 *
 * ## Isolasi = mutasi penempatan, bukan flag
 *
 * Membawa santri ke kamar isolasi adalah perpindahan fisik: placement lama ditutup
 * (`end_reason = isolation`, tempat tidur lama dibebaskan) dan placement baru
 * dibuka di kamar bertipe `isolation`. Alasannya: seluruh laporan occupancy,
 * daftar penghuni kamar, dan lingkup hak akses pengasuh (`BoardingScopeService`)
 * dibaca dari `boarding_placements`. Kalau isolasi hanya ditandai di tabel
 * kesehatan, santri masih terhitung menempati kamar lamanya dan pengasuh kamar
 * lain tetap "melihat" dia.
 *
 * Karena itu `isolate()`/`releaseIsolation()` mengembalikan `BoardingPlacement`,
 * bukan catatan kesehatan, dan keduanya berjalan dalam satu `DB::transaction`
 * dengan `lockForUpdate` pada baris kamar/tempat tidur — sama seperti mutasi biasa.
 *
 * Kamar asal disimpan di `notes` placement isolasi (JSON) supaya `release` bisa
 * mengembalikannya tanpa parameter tambahan.
 */
class BoardingHealthService
{
    /** Transisi status yang sah (§39). */
    public const TRANSITIONS = [
        BoardingHealthRecord::STATUS_REPORTED => [
            BoardingHealthRecord::STATUS_BEING_TREATED,
            BoardingHealthRecord::STATUS_RECOVERED,
            BoardingHealthRecord::STATUS_REFERRED,
        ],
        BoardingHealthRecord::STATUS_BEING_TREATED => [
            BoardingHealthRecord::STATUS_RECOVERED,
            BoardingHealthRecord::STATUS_REFERRED,
        ],
        // Catatan yang sudah dirujuk masih bisa dinyatakan pulih setelah
        // kembali dari fasilitas kesehatan; setelah pulih tidak ada lagi
        // yang bisa diubah selain membuat catatan baru.
        BoardingHealthRecord::STATUS_REFERRED => [
            BoardingHealthRecord::STATUS_RECOVERED,
        ],
        BoardingHealthRecord::STATUS_RECOVERED => [],
    ];

    /** `end_reason` placement saat isolasi (§11 memakai kolom yang sama). */
    public const END_REASON_ISOLATION = 'isolation';

    /** Kunci JSON di `boarding_placements.notes` untuk menandai isolasi. */
    public const NOTE_KEY = 'boarding_isolation';

    /**
     * Nama audit khusus. Bukan `BOARDING_PLACEMENT_CREATED`: yang dicatat di sini
     * peristiwa klinisnya (santri masuk/keluar ruang isolasi), bukan CRUD-nya.
     */
    public const EVENT_ISOLATION_STARTED = 'BOARDING_ISOLATION_STARTED';

    public const EVENT_ISOLATION_ENDED = 'BOARDING_ISOLATION_ENDED';

    public function __construct(
        protected readonly BoardingScopeService $scope,
        protected readonly BoardingStatusService $statuses,
        protected readonly BoardingNotificationService $notifications,
    ) {
    }

    // =====================================================================
    // CATATAN KESEHATAN
    // =====================================================================

    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters = [], ?User $actor = null): Builder
    {
        $allowed = $this->scope->studentIdsFor($actor);

        return BoardingHealthRecord::query()
            ->with(['student:id,full_name,nis', 'recordedBy:id,name'])
            ->forStudent(is_numeric($filters['student_id'] ?? null) ? (int) $filters['student_id'] : null)
            ->between($filters['from'] ?? null, $filters['to'] ?? null)
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->whereIn('status', (array) $filters['status']))
            ->when(
                ($filters['open_only'] ?? null) !== null && filter_var($filters['open_only'], FILTER_VALIDATE_BOOLEAN),
                fn (Builder $q) => $q->open(),
            )
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('student_id', $allowed ?: [0]))
            ->orderByDesc('date')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): BoardingHealthRecord
    {
        $student = $this->assertStudent($data['student_id'] ?? null, $actor);
        $this->assertStatus((string) ($data['status'] ?? BoardingHealthRecord::STATUS_REPORTED));

        $record = BoardingHealthRecord::create($this->payload($data) + [
            'student_id' => $student->id,
            'status' => $data['status'] ?? BoardingHealthRecord::STATUS_REPORTED,
            'recorded_by' => $actor?->id,
        ]);

        $this->notify($record, 'health_recorded');

        return $record->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BoardingHealthRecord $record, array $data, ?User $actor = null): BoardingHealthRecord
    {
        $this->assertStudent($record->student_id, $actor);

        if (array_key_exists('status', $data) && (string) $data['status'] !== (string) $record->status) {
            return $this->updateStatus($record, (string) $data['status'], $actor, $data);
        }

        $payload = $this->payload($data);

        if ($payload === []) {
            return $record;
        }

        $record->update($payload);

        return $record->refresh();
    }

    /**
     * Pindahkan status satu catatan kesehatan mengikuti `TRANSITIONS`.
     *
     * @param  array<string, mixed>  $extra  `action`/`notes` yang ikut ditulis
     */
    public function updateStatus(BoardingHealthRecord $record, string $status, ?User $actor = null, array $extra = []): BoardingHealthRecord
    {
        $this->assertStudent($record->student_id, $actor);
        $this->assertStatus($status);

        if ($status === (string) $record->status) {
            return $record;
        }

        $allowed = self::TRANSITIONS[$record->status] ?? [];

        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'Status %s tidak dapat diubah langsung menjadi %s.%s',
                    $record->statusLabel(),
                    BoardingHealthRecord::STATUS_LABELS[$status] ?? $status,
                    $allowed === [] ? ' Catatan yang sudah pulih tidak dibuka lagi — buat catatan baru.' : ' Urutan yang sah: '.$record->status.' → '.implode(' / ', $allowed).'.',
                ),
            ]);
        }

        $payload = $this->payload($extra) + ['status' => $status];

        // "Dirujuk" tanpa tujuan rujukan tidak bisa ditindaklanjuti siapa pun.
        if ($status === BoardingHealthRecord::STATUS_REFERRED && blank($payload['action'] ?? null)) {
            throw ValidationException::withMessages([
                'action' => 'Catatan yang dirujuk wajib mencantumkan tindakan/rujukan (mis. "Dibawa ke RS Islam oleh Ust. Ahmad").',
            ]);
        }

        $record->update($payload);
        $record->refresh();

        $this->notify($record, 'health_status_changed');

        return $record;
    }

    public function delete(BoardingHealthRecord $record, ?User $actor = null): void
    {
        $this->assertStudent($record->student_id, $actor);

        $record->delete();
    }

    // =====================================================================
    // ISOLASI (BAB §39 + §9/§11 untuk mekanika penempatannya)
    // =====================================================================

    /**
     * Pindahkan santri ke kamar isolasi.
     *
     * @param  array<string, mixed>|null  $context  `health_record_id`, `notes`
     */
    public function isolate(
        mixed $studentId,
        mixed $roomId,
        mixed $bedId = null,
        ?string $reason = null,
        ?User $actor = null,
        ?array $context = null,
    ): BoardingPlacement {
        $student = $this->assertStudent($studentId, $actor);
        $room = $this->isolationRoom($roomId, $student);
        $context = is_array($context) ? $context : [];

        $current = $this->statuses->currentPlacementFor((int) $student->id);

        if ($current === null) {
            throw ValidationException::withMessages([
                'student_id' => 'Santri tidak memiliki penempatan aktif di asrama, jadi tidak ada yang bisa diisolasikan.',
            ]);
        }

        if ((int) $current->room_id === (int) $room->id) {
            throw ValidationException::withMessages([
                'room_id' => 'Santri sudah berada di kamar isolasi ini.',
            ]);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'Alasan isolasi wajib diisi — ia muncul di laporan dan daftar penghuni kamar.',
            ]);
        }

        return DB::transaction(function () use ($current, $room, $bedId, $reason, $actor, $student, $context): BoardingPlacement {
            $bed = $this->pickBed($room, $bedId, $current->bed_id === null ? null : (int) $current->bed_id);

            $notes = json_encode([
                self::NOTE_KEY => [
                    'action' => 'isolate',
                    'reason' => $reason,
                    'started_at' => now()->toIso8601String(),
                    'actor_id' => $actor?->id,
                    'student_id' => (int) $student->id,
                    'health_record_id' => isset($context['health_record_id']) ? (int) $context['health_record_id'] : null,
                    // Kamar asal diingat di sini supaya `releaseIsolation()` bisa
                    // mengembalikan santri tanpa parameter tambahan.
                    'from_placement_id' => (int) $current->id,
                    'from_room_id' => $current->room_id === null ? null : (int) $current->room_id,
                    'from_bed_id' => $current->bed_id === null ? null : (int) $current->bed_id,
                    'from_dormitory_id' => $current->dormitory_id === null ? null : (int) $current->dormitory_id,
                    'note' => $context['notes'] ?? null,
                ],
            ], JSON_UNESCAPED_UNICODE);

            $placement = $this->transfer($current, $room, $bed, self::END_REASON_ISOLATION, $actor, $notes);

            $this->logIsolation($placement, 'BOARDING_ISOLATION_STARTED', $reason, $actor);
            $this->notifyPlacement($placement, 'isolation_started', $reason);

            return $placement;
        });
    }

    /**
     * Keluarkan santri dari kamar isolasi: placement isolasi ditutup
     * (`end_reason` null, bukan `isolation`, karena ini akhir dari masa isolasi),
     * dan penempatan baru dibuka di kamar tujuan — default kamar asal.
     */
    public function releaseIsolation(
        mixed $placement,
        mixed $roomId = null,
        mixed $bedId = null,
        ?string $reason = null,
        ?User $actor = null,
    ): BoardingPlacement {
        $isolation = $placement instanceof BoardingPlacement
            ? $placement
            : BoardingPlacement::query()->find($placement);

        if ($isolation === null) {
            throw ValidationException::withMessages(['placement' => 'Penempatan tidak ditemukan.']);
        }

        $this->assertStudent($isolation->student_id, $actor);

        $room = $isolation->room ?? BoardingRoom::query()->find($isolation->room_id);

        if ($room === null || ! $room->isIsolation()) {
            throw ValidationException::withMessages([
                'placement' => 'Penempatan ini bukan penempatan isolasi, jadi tidak bisa dilepas lewat jalur isolasi.',
            ]);
        }

        if (! $isolation->isActive()) {
            throw ValidationException::withMessages([
                'placement' => 'Masa isolasi ini sudah berakhir pada '.$this->dateText($isolation->end_date).'.',
            ]);
        }

        $origin = $this->isolationContext($isolation);
        $targetRoom = $roomId !== null && $roomId !== ''
            ? $this->usableRoom($roomId, $isolation)
            : $this->originRoom($origin, $isolation);

        return DB::transaction(function () use ($isolation, $targetRoom, $bedId, $reason, $actor, $origin): BoardingPlacement {
            $bed = $this->pickBed($targetRoom, $bedId, $isolation->bed_id === null ? null : (int) $isolation->bed_id);

            $notes = json_encode([
                self::NOTE_KEY => [
                    'action' => 'release',
                    'reason' => $reason,
                    'released_at' => now()->toIso8601String(),
                    'actor_id' => $actor?->id,
                    'student_id' => (int) $isolation->student_id,
                    'released_from_placement_id' => (int) $isolation->id,
                    'released_from_room_id' => $isolation->room_id === null ? null : (int) $isolation->room_id,
                    'origin_room_id' => $origin['from_room_id'] ?? null,
                    'origin_bed_id' => $origin['from_bed_id'] ?? null,
                ],
            ], JSON_UNESCAPED_UNICODE);

            // end_reason null: release bukan mutasi perpindahan, melainkan
            // berakhirnya tindakan isolasi itu sendiri.
            $placement = $this->transfer($isolation, $targetRoom, $bed, null, $actor, $notes);

            $this->logIsolation($placement, 'BOARDING_ISOLATION_ENDED', $reason, $actor);
            $this->notifyPlacement($placement, 'isolation_ended', $reason);

            return $placement;
        });
    }

    /**
     * Penghuni kamar isolasi yang masih aktif (§39) — daftar "siapa di isolasi".
     *
     * dibaca dari PENEMPATAN (kamar bertipe isolation + placement aktif), bukan
     * dari flag catatan kesehatan: isolasi adalah mutasi penempatan, jadi
     * daftarnya harus sama benarnya dengan laporan occupancy kamar.
     *
     * @return \Illuminate\Support\Collection<int, BoardingPlacement>
     */
    public function activeIsolations(?User $actor = null): \Illuminate\Support\Collection
    {
        $allowed = $this->scope->studentIdsFor($actor);

        return BoardingPlacement::query()
            ->with(['student:id,full_name,nis', 'room:id,code,name,room_type', 'bed:id,bed_number'])
            ->where('status', BoardingPlacement::STATUS_ACTIVE)
            ->whereHas('room', fn (Builder $q) => $q->where('room_type', BoardingRoom::TYPE_ISOLATION))
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('student_id', $allowed ?: [0]))
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Kamar isolasi aktif yang boleh dihuni santri ini.
     */
    public function isolationRoom(mixed $roomId, Student $student): BoardingRoom
    {
        $room = $this->usableRoom($roomId, $student);

        if (! $room->isIsolation()) {
            throw ValidationException::withMessages([
                'room_id' => 'Kamar '.$room->code.' bukan kamar isolasi (room_type harus isolation).',
            ]);
        }

        return $room;
    }

    /**
     * Kamar aktif + cocok gender + masih ada kapasitas.
     */
    protected function usableRoom(mixed $roomId, Student|BoardingPlacement $subject): BoardingRoom
    {
        $room = BoardingRoom::query()->with('floor')->find($roomId);

        if ($room === null) {
            throw ValidationException::withMessages(['room_id' => 'Kamar tidak ditemukan.']);
        }

        if (! $room->isActive()) {
            throw ValidationException::withMessages([
                'room_id' => 'Kamar '.$room->code.' berstatus '.$room->status.', tidak boleh menerima penghuni baru.',
            ]);
        }

        $student = $subject instanceof Student ? $subject : ($subject->student ?? Student::query()->find($subject->student_id));

        if (! $room->acceptsGender($student?->gender?->code)) {
            throw ValidationException::withMessages([
                'room_id' => 'Kamar '.$room->code.' '.$room->genderLabel.' tidak sesuai dengan gender santri tersebut.',
            ]);
        }

        return $room;
    }

    /**
     * Kamar asal santri sebelum diisolasi; gagal kalau ia sudah dihapus/dinonaktif.
     */
    protected function originRoom(array $origin, BoardingPlacement $isolation): BoardingRoom
    {
        $roomId = $origin['from_room_id'] ?? null;

        if ($roomId === null) {
            throw ValidationException::withMessages([
                'room_id' => 'Kamar asal isolasi ini tidak tercatat. Tentukan kamar tujuan secara eksplisit.',
            ]);
        }

        $room = BoardingRoom::query()->with('floor')->find($roomId);

        if ($room === null || ! $room->isActive()) {
            throw ValidationException::withMessages([
                'room_id' => 'Kamar asal sudah tidak aktif/terhapus. Tentukan kamar tujuan pengganti (isolation release butuh target).',
            ]);
        }

        return $room;
    }

    /**
     * Pilih tempat tidur: id eksplisit (diverifikasi milik kamar & bebas) atau
     * tempat tidur kosong pertama dalam kamar.
     */
    protected function pickBed(BoardingRoom $room, mixed $bedId, ?int $exceptBedId = null): ?BoardingBed
    {
        $query = BoardingBed::query()->where('room_id', $room->id)->lockForUpdate();

        if ($bedId !== null && $bedId !== '') {
            $bed = (clone $query)->find($bedId);

            if ($bed === null) {
                throw ValidationException::withMessages([
                    'bed_id' => 'Tempat tidur tersebut bukan bagian kamar '.$room->code.' atau sudah dihapus.',
                ]);
            }

            if ($bed->isOccupied()) {
                throw ValidationException::withMessages([
                    'bed_id' => 'Tempat tidur '.$bed->bed_number.' sudah berpenghuni.',
                ]);
            }

            return $bed;
        }

        return (clone $query)
            ->where('status', BoardingBed::STATUS_AVAILABLE)
            ->whereKeyNot($exceptBedId ?? 0)
            ->whereDoesntHave('activePlacement')
            ->orderBy('bed_number')
            ->first();
    }

    /**
     * Satu transaksi mutasi: tutup placement lama, buka placement baru, pindahkan
     * status tempat tidur, dan jaga kapasitas kamar tujuan.
     *
     * @param  string|null  $endReason  `isolation` saat masuk, null saat dilepas
     * @param  string|null  $notes      JSON penanda isolasi
     */
    protected function transfer(
        BoardingPlacement $current,
        BoardingRoom $room,
        ?BoardingBed $bed,
        ?string $endReason,
        ?User $actor,
        ?string $notes,
    ): BoardingPlacement {
        // Kapasitas kamar tujuan dihitung ulang di bawah lock: dua petugas yang
        // memindahkan santri ke kamar yang sama pada detik yang sama tidak boleh
        // sama-sama "meloloskan" pemeriksaan (§10).
        $occupied = BoardingPlacement::query()
            ->where('room_id', $room->id)
            ->where('status', BoardingPlacement::STATUS_ACTIVE)
            ->whereKeyNot($current->id)
            ->lockForUpdate()
            ->count();

        if ($occupied >= (int) $room->capacity) {
            throw ValidationException::withMessages([
                'room_id' => 'Kamar '.$room->code.' sudah penuh (kapasitas '.(int) $room->capacity.'). Pilih kamar lain.',
            ]);
        }

        $floor = $room->floor ?? BoardingFloor::query()->find($room->floor_id);

        $placement = BoardingPlacement::create([
            'student_id' => $current->student_id,
            'dormitory_id' => $room->dormitory_id,
            'building_id' => $floor?->building_id,
            'floor_id' => $room->floor_id,
            'room_id' => $room->id,
            'bed_id' => $bed?->id,
            'start_date' => now()->toDateString(),
            'status' => BoardingPlacement::STATUS_ACTIVE,
            'end_date' => null,
            'end_reason' => null,
            'notes' => $notes,
            'assigned_by' => $actor?->id,
        ]);

        // Placement lama ditutup + dirantai ke yang baru (§11), bukan dihapus.
        $current->update([
            'status' => BoardingPlacement::STATUS_INACTIVE,
            'end_date' => now()->toDateString(),
            'end_reason' => $endReason,
            'replaced_by_placement_id' => $placement->id,
        ]);

        if ($current->bed_id !== null && (int) $current->bed_id !== (int) ($bed?->id ?? 0)) {
            BoardingBed::query()->whereKey($current->bed_id)->update(['status' => BoardingBed::STATUS_AVAILABLE]);
        }

        if ($bed !== null) {
            $bed->update(['status' => BoardingBed::STATUS_OCCUPIED]);
        }

        return $placement->refresh();
    }

    /**
     * Isi `notes` penempatan isolasi tidak selalu bisa dibaca manusia; urai
     * kembali kunci aslinya bila datangnya dari petugas (JSON) atau teks biasa.
     *
     * @return array<string, mixed>
     */
    public function isolationContext(BoardingPlacement $placement): array
    {
        $decoded = json_decode((string) $placement->notes, true);

        if (is_array($decoded) && isset($decoded[self::NOTE_KEY]) && is_array($decoded[self::NOTE_KEY])) {
            return $decoded[self::NOTE_KEY];
        }

        return [];
    }

    /**
     * Aksi lintas-model (tutup placement + buka placement + dua bed) butuh satu
     * baris audit gabungan; model-modelnya sendiri sudah mencatat CRUD-nya.
     *
     * Sengaja TANPA `performedOn()`: `AppServiceProvider` menuliskan prefix
     * event dari model subject (`BOARDING_PLACEMENT_...`), padahal dua peristiwa
     * ini adalah nama aksi tersendiri yang dicari lintas model. kolom subject
     * nullable, jadi audit-nya tetap sah.
     */
    protected function logIsolation(BoardingPlacement $placement, string $event, ?string $reason, ?User $actor): void
    {
        activity('boarding')
            ->event($event)
            ->causedBy($actor)
            ->withProperties([
                'student_id' => (int) $placement->student_id,
                'placement_id' => (int) $placement->id,
                'room_id' => (int) $placement->room_id,
                'bed_id' => $placement->bed_id === null ? null : (int) $placement->bed_id,
                'reason' => $reason,
            ])
            ->log($event === self::EVENT_ISOLATION_STARTED ? 'Santri dipindahkan ke ruang isolasi.' : 'Santri dilepas dari ruang isolasi.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function payload(array $data): array
    {
        $payload = [];

        foreach (['condition', 'complaint', 'action', 'notes'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = is_string($data[$key]) ? trim($data[$key]) : $data[$key];
                $payload[$key] = ($value === null || $value === '') ? null : (string) $value;
            }
        }

        if (array_key_exists('date', $data)) {
            $payload['date'] = $data['date'];
        }

        return $payload;
    }

    protected function notify(BoardingHealthRecord $record, string $type): int
    {
        if (! $record->relationLoaded('student')) {
            $record->load('student:id,full_name,nis');
        }

        $title = $type === 'health_recorded'
            ? sprintf('Kabar kesehatan asrama: %s', (string) $record->condition)
            : sprintf('Status kesehatan berubah: %s', $record->statusLabel());

        $body = sprintf(
            'Ananda %s — %s (%s). Tindakan: %s',
            $record->student?->full_name ?? 'santri',
            (string) $record->condition,
            $record->statusLabel(),
            filled($record->action) ? (string) $record->action : 'belum ada catatan tindakan',
        );

        return $this->notifications->toParentsOf(
            (int) $record->student_id,
            $type,
            $title,
            $body,
            [
                'reference_type' => BoardingHealthRecord::class,
                'reference_id' => (int) $record->id,
                'status' => $record->status,
                'route' => '/boarding/portal/children/'.$record->student_id.'/health',
            ],
        );
    }

    /**
     * Versi teks untuk placement: kabar isolasi masuk/keluar ke orang tua.
     */
    protected function notifyPlacement(BoardingPlacement $placement, string $type, ?string $reason): int
    {
        $student = $placement->student ?? Student::query()->find($placement->student_id);

        $title = $type === 'isolation_started'
            ? 'Ananda dipindahkan ke ruang isolasi asrama'
            : 'Ananda sudah keluar dari ruang isolasi asrama';

        $body = sprintf(
            '%s. Kamar: %s. Alasan: %s',
            $student?->full_name ?? 'Santri',
            $placement->label(),
            $reason ?: 'tidak dicantumkan',
        );

        return $this->notifications->toParentsOf(
            (int) $placement->student_id,
            $type,
            $title,
            $body,
            [
                'reference_type' => BoardingPlacement::class,
                'reference_id' => (int) $placement->id,
                'status' => $placement->status,
                'route' => '/boarding/portal/children/'.$placement->student_id.'/health',
            ],
        );
    }

    /**
     * @return Student
     */
    public function assertStudent(mixed $studentId, ?User $actor): Student
    {
        $id = is_numeric($studentId) ? (int) $studentId : 0;
        $student = $id > 0 ? Student::query()->find($id) : null;

        if ($student === null) {
            throw ValidationException::withMessages(['student_id' => 'Santri tidak ditemukan.']);
        }

        $this->scope->assertCanAccessStudent($actor, (int) $student->id);

        return $student;
    }

    protected function assertStatus(string $status): void
    {
        if (! in_array($status, BoardingHealthRecord::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Status kesehatan harus salah satu dari: '.implode(', ', BoardingHealthRecord::STATUSES).'.',
            ]);
        }
    }

    protected function dateText(mixed $date): string
    {
        return $date instanceof \DateTimeInterface ? $date->format('d/m/Y') : (string) ($date ?? '-');
    }
}
