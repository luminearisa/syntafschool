<?php

namespace App\Services\Boarding;

use App\Models\BoardingHealthRecord;
use App\Models\BoardingHomecoming;
use App\Models\BoardingMusyrif;
use App\Models\BoardingPermission;
use App\Models\BoardingPlacement;
use App\Models\BoardingRoom;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * Status keberadaan santri di asrama (§42) — satu jawaban untuk dashboard,
 * kartu santri, dan laporan harian.
 *
 * ## Keputusan (berurutan, semuanya dibaca dari data terakhir)
 *
 * 1. `absent`      : tidak punya placement aktif. Santri memang tidak tinggal
 *                    di asrama (atau penempatannya sudah ditutup) — status ini
 *                    berarti "bukan santri asrama", BUKAN "alpa tidak hadir".
 * 2. `out_with_permission` : ada izin keluar yang sudah disetujui dan santri
 *                    belum di-check in kembali (status `out`, atau `approved`
 *                    dengan jendela waktu sudah dimulai).
 * 3. `home`        : ada kepulangan (§30) yang disetujui dan belum kembali.
 * 4. `sick` / `isolation` : ada catatan kesehatan aktif (`reported` /
 *                    `being_treated`). Bila kamar penempatannya bertipe
 *                    `isolation` statusnya `isolation`, sebab bagi petugas
 *                    dua-duanya butuh tindakan berbeda.
 * 5. `in_dorm`     : sisanya — ada di kamar.
 * 6. `unknown`     : id santri tidak dikenal (tidak ada barisnya di `students`).
 *
 * Prioritas dibuat tetap seperti di atas: santri izin keluar yang juga sedang
 * sakit tetap dilaporkan `out_with_permission`, karena pertanyaan pertama
 * petugas adalah "dia ada di dalam atau tidak".
 *
 * Seluruh metode bekerja bulk (satu query per sumber data) supaya daftar 500
 * santri tidak memicu 2000 query.
 */
class BoardingStatusService
{
    public const IN_DORM = 'in_dorm';

    public const OUT_WITH_PERMISSION = 'out_with_permission';

    public const HOME = 'home';

    public const SICK = 'sick';

    public const ISOLATION = 'isolation';

    public const ABSENT = 'absent';

    public const UNKNOWN = 'unknown';

    /** Urutan tetap untuk rendering kartu/legend. */
    public const STATUSES = [
        self::IN_DORM, self::OUT_WITH_PERMISSION, self::HOME,
        self::SICK, self::ISOLATION, self::ABSENT, self::UNKNOWN,
    ];

    public const STATUS_LABELS = [
        self::IN_DORM => 'Di Dalam Asrama',
        self::OUT_WITH_PERMISSION => 'Keluar (Ada Izin)',
        self::HOME => 'Pulang',
        self::SICK => 'Sakit',
        self::ISOLATION => 'Isolasi',
        self::ABSENT => 'Tidak di Asrama',
        self::UNKNOWN => 'Tidak Diketahui',
    ];

    /** Status yang berarti santri sedang berada di luar lingkungan asrama. */
    public const OUTSIDE_STATUSES = [self::OUT_WITH_PERMISSION, self::HOME];

    /**
     * @return array<string, string> label siap pakai untuk dropdown/legend
     */
    public static function options(): array
    {
        return self::STATUS_LABELS;
    }

    public static function label(?string $status): string
    {
        return self::STATUS_LABELS[$status ?? self::UNKNOWN] ?? (string) $status;
    }

    /**
     * Status satu santri.
     */
    public function statusForStudent(int $studentId): string
    {
        return $this->statusesFor([$studentId])[$studentId] ?? self::UNKNOWN;
    }

    /**
     * Peta id santri => status.
     *
     * @param  list<int>|array<int, int|null>  $studentIds
     * @return array<int, string>
     */
    public function statusesFor(array $studentIds): array
    {
        $ids = $this->normalizeIds($studentIds);

        if ($ids === []) {
            return [];
        }

        $placements = $this->placementsFor($ids);
        $permissions = $this->openPermissionsFor($ids);
        $homecomings = $this->openHomecomingsFor($ids);
        $healthRecords = $this->openHealthRecordsFor($ids);
        $knownIds = Student::query()->whereIn('id', $ids)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $statuses = [];

        foreach ($ids as $id) {
            if (! in_array($id, $knownIds, true)) {
                $statuses[$id] = self::UNKNOWN;

                continue;
            }

            $placement = $placements[$id] ?? null;

            if ($placement === null) {
                $statuses[$id] = self::ABSENT;

                continue;
            }

            if (isset($permissions[$id])) {
                $statuses[$id] = self::OUT_WITH_PERMISSION;

                continue;
            }

            if (isset($homecomings[$id])) {
                $statuses[$id] = self::HOME;

                continue;
            }

            if (isset($healthRecords[$id])) {
                $statuses[$id] = $placement->room?->room_type === BoardingRoom::TYPE_ISOLATION
                    ? self::ISOLATION
                    : self::SICK;

                continue;
            }

            $statuses[$id] = self::IN_DORM;
        }

        return $statuses;
    }

    /**
     * Rekap jumlah santri per status (bulk) — sumber angka pada dashboard §42.
     *
     * Semua status selalu muncul, termasuk yang nol, supaya frontend tidak perlu
     * menandai key hilang.
     *
     * @param  list<int>  $studentIds
     * @return array<string, int>
     */
    public function countsFor(array $studentIds): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);

        foreach ($this->statusesFor($studentIds) as $status) {
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Placement aktif satu santri, lengkap dengan kamar + asrama + tempat tidur.
     */
    public function currentPlacementFor(int $studentId): ?BoardingPlacement
    {
        return $this->placementsFor([$studentId])[$studentId] ?? null;
    }

    /**
     * Placement aktif banyak santri (terbaru bila datanya rusak ganda).
     *
     * @return array<int, BoardingPlacement>
     */
    public function placementsFor(array $studentIds): array
    {
        $ids = $this->normalizeIds($studentIds);

        if ($ids === []) {
            return [];
        }

        return BoardingPlacement::query()
            ->active()
            ->whereIn('student_id', $ids)
            ->with(['room', 'dormitory', 'building', 'floor', 'bed'])
            ->orderByDesc('id')
            ->get()
            // `orderByDesc('id')` + first(): baris penempatan terbaru menang bila
            // ada dua baris aktif karena data lama belum tertutup rapi.
            ->groupBy('student_id')
            ->map(fn (Collection $rows): BoardingPlacement => $rows->first())
            ->mapWithKeys(fn (BoardingPlacement $placement, mixed $studentId): array => [(int) $studentId => $placement])
            ->all();
    }

    /**
     * Baris santri + lokasi + pengasuh untuk tabel & kartu santri (§42).
     *
     * @param  list<int>  $studentIds
     * @return array<int, array<string, mixed>>
     */
    public function summariesFor(array $studentIds): array
    {
        $ids = $this->normalizeIds($studentIds);

        if ($ids === []) {
            return [];
        }

        $placements = $this->placementsFor($ids);
        $statuses = $this->statusesFor($ids);
        $musyrifs = $this->musyrifNamesFor($placements);

        $summaries = [];

        foreach ($ids as $id) {
            $placement = $placements[$id] ?? null;
            $room = $placement?->room;
            $dormitory = $placement?->dormitory;

            $summaries[$id] = [
                'student_id' => $id,
                'status' => $statuses[$id] ?? self::UNKNOWN,
                'status_label' => self::label($statuses[$id] ?? self::UNKNOWN),
                'in_boarding' => $placement !== null,
                'dormitory_id' => $placement?->dormitory_id === null ? null : (int) $placement->dormitory_id,
                'dormitory_code' => $dormitory?->code,
                'dormitory_name' => $dormitory?->name,
                'building_name' => $placement?->building?->name ?? $placement?->building?->code,
                'floor_name' => $placement?->floor?->displayName(),
                'room_id' => $room?->id === null ? null : (int) $room->id,
                'room_code' => $room?->code,
                'room_name' => $room?->name,
                'room_type' => $room?->room_type,
                'bed_number' => $placement?->bed?->bed_number,
                'musyrif_name' => $musyrifs[$id] ?? null,
            ];
        }

        return $summaries;
    }

    /**
     * Ringkasan satu santri.
     *
     * @return array<string, mixed>
     */
    public function summaryFor(int $studentId): array
    {
        return $this->summariesFor([$studentId])[$studentId] ?? [
            'student_id' => $studentId,
            'status' => self::UNKNOWN,
            'status_label' => self::label(self::UNKNOWN),
            'in_boarding' => false,
            'dormitory_id' => null,
            'dormitory_code' => null,
            'dormitory_name' => null,
            'building_name' => null,
            'floor_name' => null,
            'room_id' => null,
            'room_code' => null,
            'room_name' => null,
            'room_type' => null,
            'bed_number' => null,
            'musyrif_name' => null,
        ];
    }

    /**
     * Izin keluar yang masih membuat santri berada di luar (§28).
     *
     * `out` = sudah melewati gerbang dan belum check in; `approved` yang jendela
     * waktunya sudah dimulai dihitung juga, karena pencekalan check out bisa
     * tertinggal di lapangan.
     *
     * @return array<int, BoardingPermission>
     */
    protected function openPermissionsFor(array $ids): array
    {
        return BoardingPermission::query()
            ->whereIn('status', [BoardingPermission::STATUS_APPROVED, BoardingPermission::STATUS_OUT])
            ->where(fn ($q) => $q->where('status', BoardingPermission::STATUS_OUT)
                ->orWhere('start_at', '<=', now()))
            ->whereIn('student_id', $ids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_id')
            ->mapWithKeys(fn (Collection $rows, mixed $studentId): array => [(int) $studentId => $rows->first()])
            ->all();
    }

    /**
     * Kepulangan yang belum kembali (§30).
     *
     * @return array<int, BoardingHomecoming>
     */
    protected function openHomecomingsFor(array $ids): array
    {
        return BoardingHomecoming::query()
            ->whereIn('status', [BoardingHomecoming::STATUS_APPROVED, BoardingHomecoming::STATUS_OUT])
            ->where(fn ($q) => $q->where('status', BoardingHomecoming::STATUS_OUT)
                ->orWhere('home_date', '<=', now()->toDateString()))
            ->whereIn('student_id', $ids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_id')
            ->mapWithKeys(fn (Collection $rows, mixed $studentId): array => [(int) $studentId => $rows->first()])
            ->all();
    }

    /**
     * Catatan kesehatan aktif terbaru (§39).
     *
     * @return array<int, BoardingHealthRecord>
     */
    protected function openHealthRecordsFor(array $ids): array
    {
        return BoardingHealthRecord::query()
            ->open()
            ->whereIn('student_id', $ids)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_id')
            ->mapWithKeys(fn (Collection $rows, mixed $studentId): array => [(int) $studentId => $rows->first()])
            ->all();
    }

    /**
     * Nama pengasuh per santri: wali kamar bila ada penugasan setingkat kamar,
     * lalu gedung, lalu asrama. Satu query untuk seluruh baris.
     *
     * Setiap baris `boarding_musyrifs` hanya boleh dipetakan pada area TERSEMPIT
     * yang ia isi. Baris wali kamar umumnya ikut menyimpan `dormitory_id` hanya
     * sebagai konteks; kalau baris itu juga masuk peta setingkat asrama,
     * penghuni kamar lain "meminjam" nama wali kamar tetangganya — persis
     * kesalahan yang dihindari `BoardingScopeService::areasFrom()`.
     *
     * @param  array<int, BoardingPlacement>  $placements
     * @return array<int, string>
     */
    protected function musyrifNamesFor(array $placements): array
    {
        $roomIds = $this->areaIdsFrom($placements, 'room_id');
        $buildingIds = $this->areaIdsFrom($placements, 'building_id');
        $dormitoryIds = $this->areaIdsFrom($placements, 'dormitory_id');

        if ($roomIds === [] && $buildingIds === [] && $dormitoryIds === []) {
            return [];
        }

        $assignments = BoardingMusyrif::query()
            ->runningOn()
            ->where(function ($query) use ($roomIds, $buildingIds, $dormitoryIds): void {
                $query->when($roomIds !== [], fn ($q) => $q->orWhereIn('room_id', $roomIds))
                    ->when($buildingIds !== [], fn ($q) => $q->orWhereIn('building_id', $buildingIds))
                    ->when($dormitoryIds !== [], fn ($q) => $q->orWhereIn('dormitory_id', $dormitoryIds));
            })
            ->with('employee:id,full_name')
            // Area tersempit lebih dulu; bila tingkatnya sama, penugasan terbaru
            // (id terbesar) yang menang — sama seperti cara `placementsFor()`
            // dan `openPermissionsFor()` memilih baris terakhir. Tanpa pembanding
            // kedua ini hasilnya berubah-ubah kalau satu asrama punya beberapa
            // pengasuh setingkat asrama.
            ->orderByRaw('case when room_id is not null then 1 when building_id is not null then 2 when dormitory_id is not null then 3 else 4 end')
            ->orderByDesc('id')
            ->get();

        $byRoom = [];
        $byBuilding = [];
        $byDormitory = [];

        foreach ($assignments as $assignment) {
            $name = $assignment->employee?->full_name;

            if ($name === null) {
                continue;
            }

            if ($assignment->room_id !== null) {
                $byRoom[(int) $assignment->room_id] ??= $name;
            } elseif ($assignment->building_id !== null) {
                $byBuilding[(int) $assignment->building_id] ??= $name;
            } elseif ($assignment->dormitory_id !== null) {
                $byDormitory[(int) $assignment->dormitory_id] ??= $name;
            }
        }

        $names = [];

        foreach ($placements as $studentId => $placement) {
            $name = null;

            foreach ([
                $placement->room_id === null ? null : ($byRoom[(int) $placement->room_id] ?? null),
                $placement->building_id === null ? null : ($byBuilding[(int) $placement->building_id] ?? null),
                $placement->dormitory_id === null ? null : ($byDormitory[(int) $placement->dormitory_id] ?? null),
            ] as $candidate) {
                if ($candidate !== null) {
                    $name = $candidate;

                    break;
                }
            }

            if ($name !== null) {
                $names[(int) $studentId] = $name;
            }
        }

        return $names;
    }

    /**
     * Kumpulan id area (kamar/gedung/asrama) dari sekumpulan penempatan.
     *
     * @param  array<int, BoardingPlacement>  $placements
     * @return list<int>
     */
    protected function areaIdsFrom(array $placements, string $column): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (BoardingPlacement $p): ?int => $p->{$column} === null ? null : (int) $p->{$column},
            $placements,
        ))));
    }

    /**
     * @param  array<array-key, mixed>  $studentIds
     * @return list<int>
     */
    protected function normalizeIds(array $studentIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (mixed $id): ?int => $id === null ? null : (int) $id,
            $studentIds,
        ), static fn (?int $id): bool => $id !== null && $id > 0)));

        sort($ids);

        return $ids;
    }
}
