<?php

namespace App\Services\Boarding;

use App\Models\BoardingAchievement;
use App\Models\BoardingDisciplinaryAction;
use App\Models\BoardingHealthRecord;
use App\Models\BoardingHomecoming;
use App\Models\BoardingPermission;
use App\Models\BoardingViolation;
use App\Models\BoardingVisitor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Laporan modul asrama (§43–§44): satu sumber baris untuk tampilan tabel di
 * UI maupun berkas CSV. Dua-duanya membaca metode yang sama, jadi angka
 * laporan tidak bisa berbeda antara layar dan ekspor.
 *
 * Semua baris menghormati `BoardingScopeService` — wali kamar mengekspor
 * laporan untuk binaannya sendiri, tidak lebih.
 */
class BoardingReportService
{
    public const KIND_VIOLATIONS = 'violations';

    public const KIND_POINTS = 'points';

    public const KIND_ATTENDANCE = 'attendance';

    public const KIND_PERMISSIONS = 'permissions';

    public const KIND_HOMECOMINGS = 'homecomings';

    public const KIND_VISITORS = 'visitors';

    public const KIND_ACHIEVEMENTS = 'achievements';

    public const KIND_HEALTH = 'health';

    public const KIND_ACTIONS = 'actions';

    public const KIND_LABELS = [
        self::KIND_VIOLATIONS => 'Laporan Pelanggaran',
        self::KIND_POINTS => 'Rekap Poin Santri',
        self::KIND_ATTENDANCE => 'Rekap Absensi',
        self::KIND_PERMISSIONS => 'Laporan Izin Keluar',
        self::KIND_HOMECOMINGS => 'Laporan Kepulangan',
        self::KIND_VISITORS => 'Laporan Kunjungan',
        self::KIND_ACHIEVEMENTS => 'Laporan Prestasi',
        self::KIND_HEALTH => 'Laporan Kesehatan',
        self::KIND_ACTIONS => 'Antrean Tindakan Pendisiplinan',
    ];

    public function __construct(
        protected readonly BoardingScopeService $scope,
        protected readonly BoardingViolationService $violations,
        protected readonly BoardingAttendanceService $attendances,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function kinds(): array
    {
        return self::KIND_LABELS;
    }

    public static function supports(string $kind): bool
    {
        return array_key_exists($kind, self::KIND_LABELS);
    }

    /**
     * Kolom laporan: key baris => judul kolom CSV.
     *
     * @return array<string, string>
     */
    public function headers(string $kind): array
    {
        return match ($kind) {
            self::KIND_VIOLATIONS => [
                'date' => 'Tanggal', 'student' => 'Santri', 'nis' => 'NIS', 'type_code' => 'Kode',
                'type' => 'Jenis Pelanggaran', 'category' => 'Kategori', 'severity' => 'Keparahan',
                'points' => 'Poin', 'status' => 'Status', 'narrative' => 'Uraian',
            ],
            self::KIND_POINTS => [
                'student' => 'Santri', 'nis' => 'NIS', 'violations' => 'Jumlah Pelanggaran',
                'total_points' => 'Total Poin', 'open' => 'Belum Ditindaklanjuti',
                'last_date' => 'Pelanggaran Terakhir',
            ],
            self::KIND_ATTENDANCE => [
                'student' => 'Santri', 'nis' => 'NIS', 'total' => 'Total Absen', 'present' => 'Hadir',
                'late' => 'Terlambat', 'excused' => 'Izin', 'unexcused' => 'Alpa',
                'not_present' => 'Belum Tercatat', 'percentage' => '% Kehadiran',
            ],
            self::KIND_PERMISSIONS => [
                'student' => 'Santri', 'nis' => 'NIS', 'type' => 'Jenis Izin', 'start_at' => 'Mulai',
                'expected_return_at' => 'Perkiraan Kembali', 'actual_return_at' => 'Kembali Nyata',
                'status' => 'Status', 'late_return' => 'Terlambat',
            ],
            self::KIND_HOMECOMINGS => [
                'student' => 'Santri', 'nis' => 'NIS', 'type' => 'Jenis Kepulangan', 'home_date' => 'Tanggal Pulang',
                'return_date' => 'Tanggal Kembali', 'actual_return_date' => 'Kembali Nyata',
                'status' => 'Status', 'late_return' => 'Terlambat',
            ],
            self::KIND_VISITORS => [
                'visit_date' => 'Tanggal', 'visitor' => 'Nama Tamu', 'relationship' => 'Hubungan',
                'student' => 'Santri Dikunjungi', 'purpose' => 'Keperluan',
                'check_in_at' => 'Check In', 'check_out_at' => 'Check Out', 'status' => 'Status',
            ],
            self::KIND_ACHIEVEMENTS => [
                'date' => 'Tanggal', 'student' => 'Santri', 'nis' => 'NIS', 'title' => 'Prestasi',
                'category' => 'Kategori', 'level' => 'Jenjang',
            ],
            self::KIND_HEALTH => [
                'date' => 'Tanggal', 'student' => 'Santri', 'nis' => 'NIS', 'condition' => 'Kondisi',
                'action' => 'Tindakan', 'status' => 'Status',
            ],
            self::KIND_ACTIONS => [
                'date' => 'Tanggal', 'student' => 'Santri', 'nis' => 'NIS', 'action' => 'Tindakan',
                'status' => 'Status', 'officer' => 'Petugas', 'notes' => 'Catatan', 'source' => 'Sumber',
            ],
            default => [],
        };
    }

    /**
     * Baris laporan, sudah berbentuk datar (kamus string/int) — siap tabel & CSV.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function rows(string $kind, array $filters = [], ?User $actor = null): array
    {
        return match ($kind) {
            self::KIND_VIOLATIONS => $this->violationRows($filters, $actor),
            self::KIND_POINTS => $this->pointRows($filters, $actor),
            self::KIND_ATTENDANCE => $this->attendanceRows($filters, $actor),
            self::KIND_PERMISSIONS => $this->permissionRows($filters, $actor),
            self::KIND_HOMECOMINGS => $this->homecomingRows($filters, $actor),
            self::KIND_VISITORS => $this->visitorRows($filters, $actor),
            self::KIND_ACHIEVEMENTS => $this->achievementRows($filters, $actor),
            self::KIND_HEALTH => $this->healthRows($filters, $actor),
            self::KIND_ACTIONS => $this->actionRows($filters, $actor),
            default => [],
        };
    }

    public function fileName(string $kind, array $filters = []): string
    {
        $from = filled($filters['from'] ?? null) ? (string) $filters['from'] : 'awal';
        $to = filled($filters['to'] ?? null) ? (string) $filters['to'] : now()->toDateString();

        return 'boarding-'.$kind.'-'.$from.'-'.$to.'.csv';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function violationRows(array $filters, ?User $actor): array
    {
        return $this->violations->query($filters, $actor)->limit($this->limit($filters))->get()
            ->map(fn (BoardingViolation $v): array => [
                'date' => $v->date?->toDateString(),
                'student' => $v->student?->full_name,
                'nis' => $v->student?->nis,
                'type_code' => $v->type?->code,
                'type' => $v->type?->name,
                'category' => $v->type?->categoryLabel(),
                'severity' => $v->type?->severityLabel(),
                'points' => (int) $v->points,
                'status' => $v->statusLabel(),
                'narrative' => $v->narrative,
            ])
            ->values()
            ->all();
    }

    /**
     * Rekap poin per santri — dibaca dari kolom `points` catatan (snapshot),
     * bukan dari master, sama seperti seluruh perhitungan poin (§37).
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function pointRows(array $filters, ?User $actor): array
    {
        $grouped = $this->violations->query($filters, $actor)
            ->limit($this->limit($filters))
            ->get()
            ->groupBy('student_id');

        $rows = [];

        foreach ($grouped as $studentId => $items) {
            $first = $items->first();

            $rows[] = [
                'student' => $first?->student?->full_name,
                'nis' => $first?->student?->nis,
                'violations' => $items->count(),
                'total_points' => (int) $items->sum('points'),
                'open' => $items->where('status', \App\Models\BoardingViolation::STATUS_OPEN)->count(),
                'last_date' => $items->max(fn ($v): ?string => $v->date?->toDateString()),
                '_student_id' => (int) $studentId,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['total_points'] <=> $a['total_points']);

        return array_values(array_map(fn (array $row): array => array_diff_key($row, ['_student_id' => null]), $rows));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function attendanceRows(array $filters, ?User $actor): array
    {
        if ($actor === null) {
            return [];
        }

        $recap = $this->attendances->recap([
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'dormitory_id' => $filters['dormitory_id'] ?? null,
            'room_id' => $filters['room_id'] ?? null,
            'activity_id' => $filters['activity_id'] ?? null,
        ], $actor);

        return array_map(fn (array $row): array => [
            'student' => $row['student']['full_name'] ?? null,
            'nis' => $row['student']['nis'] ?? null,
            'total' => (int) $row['total'],
            'present' => (int) $row['present'],
            'late' => (int) $row['late'],
            'excused' => (int) $row['excused'],
            'unexcused' => (int) $row['unexcused'],
            'not_present' => (int) $row['not_present'],
            'percentage' => $row['percentage'] ?? 0,
        ], $recap['students'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function permissionRows(array $filters, ?User $actor): array
    {
        return $this->scoped(BoardingPermission::query(), $actor, $filters)
            ->limit($this->limit($filters))
            ->get()
            ->map(fn (BoardingPermission $p): array => [
                'student' => $p->student?->full_name,
                'nis' => $p->student?->nis,
                'type' => $p->typeLabel(),
                'start_at' => $p->start_at?->toIso8601String(),
                'expected_return_at' => $p->expected_return_at?->toIso8601String(),
                'actual_return_at' => $p->actual_return_at?->toIso8601String(),
                'status' => $p->statusLabel(),
                'late_return' => $p->late_return ? 'Ya' : 'Tidak',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function homecomingRows(array $filters, ?User $actor): array
    {
        return $this->scoped(BoardingHomecoming::query(), $actor, $filters)
            ->limit($this->limit($filters))
            ->get()
            ->map(fn (BoardingHomecoming $h): array => [
                'student' => $h->student?->full_name,
                'nis' => $h->student?->nis,
                'type' => $h->typeLabel(),
                'home_date' => $h->home_date?->toDateString(),
                'return_date' => $h->return_date?->toDateString(),
                'actual_return_date' => $h->actual_return_date?->toDateString(),
                'status' => $h->statusLabel(),
                'late_return' => $h->late_return ? 'Ya' : 'Tidak',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function visitorRows(array $filters, ?User $actor): array
    {
        $allowed = $this->scope->studentIdsFor($actor);

        return BoardingVisitor::query()
            ->with('student:id,full_name,nis')
            ->when(is_numeric($filters['student_id'] ?? null), fn (Builder $q) => $q->where('student_id', (int) $filters['student_id']))
            ->when(filled($filters['from'] ?? null), fn (Builder $q) => $q->whereDate('visit_date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn (Builder $q) => $q->whereDate('visit_date', '<=', $filters['to']))
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('student_id', $allowed ?: [0]))
            ->limit($this->limit($filters))
            ->orderBy('visit_date')
            ->orderBy('id')
            ->get()
            ->map(fn (BoardingVisitor $v): array => [
                'visit_date' => $v->visit_date?->toDateString(),
                'visitor' => $v->visitor_name,
                'relationship' => $v->relationship,
                'student' => $v->student?->full_name,
                'purpose' => $v->purpose,
                'check_in_at' => $v->check_in_at?->toIso8601String(),
                'check_out_at' => $v->check_out_at?->toIso8601String(),
                'status' => $v->statusLabel(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function achievementRows(array $filters, ?User $actor): array
    {
        $allowed = $this->scope->studentIdsFor($actor);

        return BoardingAchievement::query()
            ->with('student:id,full_name,nis')
            ->when(is_numeric($filters['student_id'] ?? null), fn (Builder $q) => $q->where('student_id', (int) $filters['student_id']))
            ->when(filled($filters['from'] ?? null), fn (Builder $q) => $q->whereDate('date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn (Builder $q) => $q->whereDate('date', '<=', $filters['to']))
            ->when(filled($filters['category'] ?? null), fn (Builder $q) => $q->where('category', $filters['category']))
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('student_id', $allowed ?: [0]))
            ->limit($this->limit($filters))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (BoardingAchievement $a): array => [
                'date' => $a->date?->toDateString(),
                'student' => $a->student?->full_name,
                'nis' => $a->student?->nis,
                'title' => $a->title,
                'category' => $a->categoryLabel(),
                'level' => $a->levelLabel(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function healthRows(array $filters, ?User $actor): array
    {
        $allowed = $this->scope->studentIdsFor($actor);

        return BoardingHealthRecord::query()
            ->with('student:id,full_name,nis')
            ->when(is_numeric($filters['student_id'] ?? null), fn (Builder $q) => $q->where('student_id', (int) $filters['student_id']))
            ->when(filled($filters['from'] ?? null), fn (Builder $q) => $q->whereDate('date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn (Builder $q) => $q->whereDate('date', '<=', $filters['to']))
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('student_id', $allowed ?: [0]))
            ->limit($this->limit($filters))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (BoardingHealthRecord $r): array => [
                'date' => $r->date?->toDateString(),
                'student' => $r->student?->full_name,
                'nis' => $r->student?->nis,
                'condition' => $r->condition,
                'action' => $r->action,
                'status' => $r->statusLabel(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function actionRows(array $filters, ?User $actor): array
    {
        return $this->violations->actionQuery($filters, $actor)
            ->limit($this->limit($filters))
            ->get()
            ->map(fn (BoardingDisciplinaryAction $a): array => [
                'date' => $a->date?->toDateString(),
                'student' => $a->student?->full_name,
                'nis' => $a->student?->nis,
                'action' => $a->actionLabel(),
                'status' => $a->statusLabel(),
                'officer' => $a->officer,
                'notes' => $a->notes,
                'source' => $a->violation_id === null ? 'Otomatis (ambang poin)' : 'Manual/rujukan pelanggaran',
            ])
            ->values()
            ->all();
    }

    /**
     * Query model perizinan/kepulangan dengan filter tanggal + lingkup santri.
     *
     * @param  Builder<BoardingPermission|BoardingHomecoming>  $query
     * @return Builder<BoardingPermission|BoardingHomecoming>
     */
    protected function scoped(Builder $query, ?User $actor, array $filters): Builder
    {
        $allowed = $this->scope->studentIdsFor($actor);
        $dateColumn = $query->getModel() instanceof BoardingHomecoming ? 'home_date' : 'start_at';

        return $query
            ->with('student:id,full_name,nis')
            ->when(is_numeric($filters['student_id'] ?? null), fn (Builder $q) => $q->where('student_id', (int) $filters['student_id']))
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(filled($filters['from'] ?? null), fn (Builder $q) => $q->whereDate($dateColumn, '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn (Builder $q) => $q->whereDate($dateColumn, '<=', $filters['to']))
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('student_id', $allowed ?: [0]))
            ->orderBy($dateColumn)
            ->orderBy('id');
    }

    /**
     * Plafon baris laporan; ekspor penuh butuh keputusan sadar (?limit=), bukan
     * diam-diam menarik ribuan baris.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function limit(array $filters): int
    {
        return max(1, min(10000, (int) ($filters['limit'] ?? 2000)));
    }
}
