<?php

namespace App\Services\Boarding;

use App\Models\BoardingActivity;
use App\Models\BoardingActivitySchedule;
use App\Models\BoardingAttendance;
use App\Models\BoardingAttendanceType;
use App\Models\BoardingDormitory;
use App\Models\BoardingGroup;
use App\Models\BoardingGroupMember;
use App\Models\BoardingMusyrif;
use App\Models\BoardingPlacement;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRfidCard;
use App\Services\Wallet\WalletRfidService;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Absensi kegiatan asrama (§16–§26): roster, pencatatan manual, massal
 * ("Hadir Semua"), koreksi ber-audit, scan RFID, dan rekapitulasi.
 *
 * ## Kenapa satu service untuk semua jalur tulis
 *
 * Absensi bisa masuk dari tiga pintu — petugas mengetik satu baris, petugas
 * menekan "Hadir Semua" lalu mengoreksi beberapa baris, dan santri menempelkan
 * kartu di gerbang. Ketiganya HARUS menghasilkan baris dengan bentuk yang sama
 * (kunci unik, ambang telat, penanda sumber) kalau rekap bulanan masih berarti.
 * Karena itu aturan utamanya ada di satu tempat ini dan controller hanya
 * meneruskan (`record()` adalah satu-satunya tempat baris absensi dilahirkan).
 *
 * ## Aturan yang ditopang kelas ini
 *
 * 1. **Satu santri, satu kegiatan, satu tanggal, satu sesi** (§24). Ditolak di
 *    service dengan `ValidationException` (422 berkoncal, bukan 500) DAN di
 *    constraint unik database; insert yang tetap menabrak — dua petugas menekan
 *    tombol bersamaan — diterjemahkan kembali menjadi 422 yang sama.
 * 2. **Koreksi tidak menimpa diam-diam** (§24, §26). `revise()` menaikkan
 *    `revision`, mengisi `revised_by`/`revised_at`, dan menulis `activity_log`
 *    `BOARDING_ATTENDANCE_UPDATED` berisi sebelum/sesudah. Jalur massal sengaja
 *    TIDAK menaikkan revision: ia bukan koreksi atas kesalahan pengasuh,
 *    melainkan lanjutan layar yang sama — tetapi auditnya tetap ditulis.
 * 3. **Hadirnya santri dari penempatan, bukan dari daftar manual** (§7, §17):
 *    roster dibangun dari `boarding_placements` aktif (per kamar/asrama) atau
 *    anggota kelompok (`boarding_group_members`) — sehingga santri yang sudah
 *    pindah kamar tidak ikut muncul.
 * 4. **Telat dihitung dari jadwal, bukan dari perasaan** (§25): ambang = jam
 *    mulai jadwal yang berlaku pada hari itu + `late_after_minutes` kegiatan.
 *    Tanpa jadwal atau tanpa ambang, `is_late` tetap false — sistem tidak boleh
 *    mengarang angka.
 * 5. **Tipe absensi dibatasi konfigurasi kegiatan** (§18): selama kegiatan punya
 *    baris pivot, hanya tipe yang ditempelkan padanya (plus tipe defaultnya)
 *    yang boleh dipakai. Kegiatan tanpa konfigurasi menerima semua tipe aktif.
 * 6. **Pemakaian kartu RFID memakai identitas modul Wallet** (§23, §61): UID
 *    dinormalkan lewat `WalletRfidService::normalizeUid()` yang sama, kartu mati
 *    ditolak, dan pemiliknya harus santri berpenempatan aktif.
 * 7. **Pengasuh hanya menyentuh santri binaannya** (§54): setiap jalur tulis
 *    melewati `BoardingScopeService::assertCanAccessStudent()` (403), dan setiap
 *    jalur baca memotong daftar id lebih dulu.
 *
 * Notifikasi orang tua dikirim SETELAH transaksi commit dan sekali per
 * (santri, kegiatan, tanggal, sesi) — kunci dedupenya `reference_id` baris
 * absensi itu sendiri, jadi pengulangan aksi tidak menumpuk pesan (§56).
 */
class BoardingAttendanceService
{
    /** Tipe notifikasi untuk orang tua (§56). */
    public const NOTIFY_ALPHA = 'attendance_alpha';

    public const NOTIFY_SICK = 'attendance_sick';

    /** Scan RFID dianggap "kegiatan hari ini" bila sekarang jatuh dalam jendela ini. */
    public const SCAN_WINDOW_BEFORE_MINUTES = 60;

    public const SCAN_WINDOW_AFTER_MINUTES = 0;

    /** @var array<int, list<int>> cache id tipe absensi yang sah per kegiatan */
    private array $allowedTypeCache = [];

    public function __construct(
        private readonly BoardingScopeService $scope,
        private readonly BoardingNotificationService $notifications,
        private readonly BoardingSettingsService $settings,
    ) {
    }

    // =====================================================================
    // BACA
    // =====================================================================

    /**
     * Daftar absensi + filter (layar "Absensi Kegiatan").
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<BoardingAttendance>
     */
    public function query(array $filters = [], ?User $actor = null): Builder
    {
        $allowed = $this->scope->studentIdsFor($actor);

        return BoardingAttendance::query()
            ->with($this->eagerLoads())
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('student_id', $allowed ?: [-1]))
            ->when($this->integer($filters, 'activity_id'), fn (Builder $q, int $id) => $q->forActivity($id))
            ->when($this->integer($filters, 'student_id'), fn (Builder $q, int $id) => $q->forStudent($id))
            ->when($this->integer($filters, 'attendance_type_id'), fn (Builder $q, int $id) => $q->where('attendance_type_id', $id))
            ->when($this->date($filters, 'date'), fn (Builder $q, string $date) => $q->onDate($date))
            ->when(
                $this->date($filters, 'from') || $this->date($filters, 'to'),
                fn (Builder $q) => $q->between($this->date($filters, 'from'), $this->date($filters, 'to')),
            )
            ->when($this->text($filters, 'session'), fn (Builder $q, string $session) => $q->where('session', $session))
            ->when($this->text($filters, 'source'), fn (Builder $q, string $source) => $q->where('source', $source))
            ->when($this->boolean($filters, 'is_late'), fn (Builder $q) => $q->where('is_late', true))
            ->when($this->integer($filters, 'dormitory_id'), fn (Builder $q, int $id) => $q->whereIn(
                'student_id',
                $this->placedStudentIds(fn (Builder $p) => $p->forDormitory($id)),
            ))
            ->when($this->integer($filters, 'room_id'), fn (Builder $q, int $id) => $q->whereIn(
                'student_id',
                $this->placedStudentIds(fn (Builder $p) => $p->forRoom($id)),
            ))
            ->when($this->integer($filters, 'group_id'), fn (Builder $q, int $id) => $q->whereIn(
                'student_id',
                $this->groupStudentIds($id),
            ))
            ->when($this->text($filters, 'category'), fn (Builder $q, string $category) => $q->whereHas(
                'attendanceType',
                fn (Builder $t) => $t->where('category', $category),
            ))
            ->when($this->text($filters, 'search'), function (Builder $q, string $term): void {
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->whereHas('student', fn (Builder $s) => $s->where('full_name', 'like', "%$term%")
                        ->orWhere('nis', 'like', "%$term%"));

                    if (ctype_digit($term)) {
                        $inner->orWhere('student_id', (int) $term);
                    }
                });
            })
            ->reorder('boarding_attendances.date', 'desc')
            ->reorder('id', 'desc');
    }

    /**
     * Riwayat absensi satu santri — dipakai kartu santri & portal orang tua.
     *
     * @param  array<string, mixed>  $params
     * @return Builder<BoardingAttendance>
     */
    public function history(Student $student, array $params = [], ?User $actor = null): Builder
    {
        $this->scope->assertCanAccessStudent($actor, (int) $student->id);

        return $this->query(array_merge($params, ['student_id' => $student->id]), $actor)
            ->reorder('boarding_attendances.date', 'desc')
            ->reorder('id', 'desc');
    }

    /**
     * "Sistem menampilkan santri" (§24): daftar target absensi satu kegiatan
     * pada satu tanggal & sesi, lengkap dengan catatan yang sudah ada.
     *
     * Target diambil dari penempatan aktif (per kamar / per asrama) atau dari
     * keanggotaan kelompok — bukan dari daftar tangan — lalu dipotong hak akses
     * pengasuh. Santri yang sudah punya baris absensi ikut ditampilkan dengan
     * statusnya supaya layar "Hadir Semua" bisa langsung mengoreksi.
     *
     * @param  array<string, mixed>  $params  activity_id, date, session, group_id|room_id|dormitory_id
     * @return array<string, mixed>
     */
    public function roster(User $actor, array $params): array
    {
        $activity = $this->resolveActivity($params['activity_id'] ?? null);
        $date = $this->date($params, 'date') ?? Carbon::today()->toDateString();
        $session = $this->sessionOf($params['session'] ?? null);

        $studentIds = $this->targetStudentIds($actor, $params);

        $students = Student::query()
            ->select('id', 'full_name', 'nis')
            ->whereIn('id', $studentIds ?: [-1])
            ->orderBy('full_name')
            ->orderBy('id')
            ->get();

        $placements = $this->placementsFor($studentIds);
        $existing = $this->existingFor($activity, $date, $session, $studentIds);

        $rows = $students->map(fn (Student $student): array => $this->rosterRow(
            $student,
            $placements->get((int) $student->id),
            $existing->get((int) $student->id),
        ))->all();

        $recorded = $existing->count();

        return [
            'activity' => $this->activitySummary($activity),
            'date' => $date,
            'session' => $session,
            'schedule' => $this->scheduleSummary(
                $this->scheduleFor($activity, $date, $studentIds, $placements),
                $activity,
            ),
            'scope' => [
                'group_id' => $this->integer($params, 'group_id'),
                'room_id' => $this->integer($params, 'room_id'),
                'dormitory_id' => $this->integer($params, 'dormitory_id'),
                'restricted' => $this->scope->restricted($actor),
                'total' => count($rows),
            ],
            'attendance_types' => $this->availableTypes($activity),
            'summary' => [
                'total' => count($rows),
                'recorded' => $recorded,
                'pending' => max(0, count($rows) - $recorded),
                'present' => $existing->filter(fn (BoardingAttendance $a): bool => (bool) $a->attendanceType?->is_present)->count(),
                'late' => $existing->filter(fn (BoardingAttendance $a): bool => (bool) $a->is_late)->count(),
                'unrecorded' => max(0, count($rows) - $recorded),
            ],
            'students' => $rows,
        ];
    }

    /**
     * Rekapitulasi kehadiran (§43): total per santri dibagi menurut sifat tipe
     * absensi, dengan persentase kehadiran = hadir / total tercatat.
     *
     * Penyebutnya adalah baris absensi yang BENAR-BENAR ada pada rentang itu,
     * bukan jumlah hari kalender: kegiatan yang tidak pernah diambil absensinya
     * tidak boleh menurunkan angka santri. Batas bawah/atas selalu tanggal
     * (bukan datetime) agar hari pertama dan terakhir ikut terhitung.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function recap(array $params, User $actor): array
    {
        $to = $this->date($params, 'to') ?? Carbon::today()->toDateString();
        $from = $this->date($params, 'from') ?? Carbon::parse($to)->startOfMonth()->toDateString();

        if ($from > $to) {
            throw ValidationException::withMessages([
                'from' => 'Tanggal mulai tidak boleh setelah tanggal selesai.',
            ]);
        }

        $groupId = $this->integer($params, 'group_id');
        $roomId = $this->integer($params, 'room_id');
        $dormitoryId = $this->integer($params, 'dormitory_id');
        $activityId = $this->integer($params, 'activity_id');

        $allowed = $this->scope->studentIdsFor($actor);

        // Semua pembatas santri (hak akses, kelompok, kamar/asrama) diiris jadi
        // satu daftar: `null` berarti "tidak dibatasi sama sekali", daftar kosong
        // berarti "tidak ada yang boleh dilihat" — dua hal yang berbeda (§54).
        $restrictions = array_values(array_filter([
            $allowed,
            $groupId !== null ? $this->groupStudentIds($groupId) : null,
            ($roomId !== null || $dormitoryId !== null)
                ? $this->placedStudentIds(fn (Builder $p) => $p
                    ->when($roomId !== null, fn (Builder $r) => $r->forRoom($roomId))
                    ->when($dormitoryId !== null, fn (Builder $d) => $d->forDormitory($dormitoryId)))
                : null,
        ], static fn (?array $ids): bool => $ids !== null));

        $studentIds = $restrictions === []
            ? null
            : array_reduce(
                $restrictions,
                static fn (?array $carry, array $next): array => $carry === null
                    ? $next
                    : array_values(array_intersect($carry, $next)),
                null,
            );

        $rows = BoardingAttendance::query()
            ->with($this->eagerLoads())
            ->between($from, $to)
            ->when($activityId !== null, fn (Builder $q) => $q->forActivity($activityId))
            ->when($studentIds !== null, fn (Builder $q) => $q->whereIn('student_id', $studentIds ?: [-1]))
            ->get();

        $placements = $this->placementsFor($rows->pluck('student_id')->map(fn (mixed $id): int => (int) $id)->all());

        /** @var array<int, array<string, mixed>> $perStudent */
        $perStudent = [];

        foreach ($rows as $attendance) {
            $id = (int) $attendance->student_id;

            $perStudent[$id] ??= [
                'student' => [
                    'id' => $id,
                    'full_name' => $attendance->student?->full_name,
                    'nis' => $attendance->student?->nis,
                ],
                'placement' => $this->placementSummary($placements->get($id)),
                'total' => 0,
                'present' => 0,
                'late' => 0,
                'excused' => 0,
                'unexcused' => 0,
                'not_present' => 0,
                'types' => [],
                'corrected' => 0,
                'first_date' => $attendance->date?->toDateString(),
                'last_date' => $attendance->date?->toDateString(),
            ];

            $bucket = &$perStudent[$id];
            $type = $attendance->attendanceType;

            $bucket['total']++;
            $bucket['present'] += $type?->is_present ? 1 : 0;
            $bucket['not_present'] += $type?->is_present ? 0 : 1;
            $bucket['late'] += $this->isLateRow($attendance) ? 1 : 0;
            $bucket['excused'] += $type?->category === BoardingAttendanceType::CATEGORY_EXCUSED ? 1 : 0;
            $bucket['unexcused'] += $type?->category === BoardingAttendanceType::CATEGORY_UNEXCUSED ? 1 : 0;
            $bucket['corrected'] += $attendance->revision > 1 ? 1 : 0;

            $key = (string) ($type?->code ?? 'TANPA-TIPE');
            $bucket['types'][$key] = ($bucket['types'][$key] ?? 0) + 1;

            $date = $attendance->date?->toDateString();
            $bucket['first_date'] = $bucket['first_date'] === null ? $date : min($bucket['first_date'], (string) $date);
            $bucket['last_date'] = $bucket['last_date'] === null ? $date : max($bucket['last_date'], (string) $date);

            unset($bucket);
        }

        $students = collect($perStudent)
            ->map(function (array $bucket): array {
                $bucket['percentage'] = $bucket['total'] > 0
                    ? round($bucket['present'] / $bucket['total'] * 100, 1)
                    : 0.0;

                ksort($bucket['types']);

                return $bucket;
            })
            ->sortBy(static fn (array $bucket): string => Str::lower((string) ($bucket['student']['full_name'] ?? '')))
            ->values()
            ->all();

        $totals = [
            'students' => count($students),
            'records' => $rows->count(),
            'present' => (int) $rows->filter(fn (BoardingAttendance $a): bool => (bool) $a->attendanceType?->is_present)->count(),
            'not_present' => (int) $rows->filter(fn (BoardingAttendance $a): bool => ! $a->attendanceType?->is_present)->count(),
            'late' => (int) $rows->filter(fn (BoardingAttendance $a): bool => $this->isLateRow($a))->count(),
            'excused' => (int) $rows->filter(fn (BoardingAttendance $a): bool => $a->attendanceType?->category === BoardingAttendanceType::CATEGORY_EXCUSED)->count(),
            'unexcused' => (int) $rows->filter(fn (BoardingAttendance $a): bool => $a->attendanceType?->category === BoardingAttendanceType::CATEGORY_UNEXCUSED)->count(),
        ];

        $totals['percentage'] = $totals['records'] > 0
            ? round($totals['present'] / $totals['records'] * 100, 1)
            : 0.0;

        return [
            'from' => $from,
            'to' => $to,
            'activity' => $activityId === null ? null : $this->activitySummary($this->resolveActivity($activityId)),
            'filters' => ['dormitory_id' => $dormitoryId, 'room_id' => $roomId, 'group_id' => $groupId],
            'attendance_types' => BoardingAttendanceType::query()
                ->orderBy('id')
                ->get(['id', 'code', 'name', 'category', 'is_present', 'color'])
                ->map(fn (BoardingAttendanceType $type): array => [
                    'id' => (int) $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'category' => $type->category,
                    'category_label' => $type->categoryLabel(),
                    'is_present' => (bool) $type->is_present,
                    'color' => $type->color,
                ])
                ->all(),
            'totals' => $totals,
            'students' => $students,
        ];
    }

    /**
     * Pantauan pengisian absensi per asrama (§43 varian — mengikuti pola
     * modul referensi): untuk satu tanggal, tiap asrama dinilai per kegiatan
     * aktif apakah absensinya sudah lengkap (tercatat ≥ sasaran), sebagian,
     * atau belum sama sekali; plus persentase kelengkapan bulan berjalan.
     *
     * Semantik bilangan: `targets` = jumlah santri dengan penempatan AKTIF
     * saat ini (snapshot), sehingga persentase bulanan adalah aproksimasi
     * bila ada mutasi penempatan di tengah bulan — disengaja demi kueri yang
     * tetap murah.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function monitoring(array $params, User $actor): array
    {
        $date = $this->date($params, 'date') ?? Carbon::today()->toDateString();

        $month = isset($params['month']) && is_string($params['month'])
            ? Carbon::createFromFormat('Y-m', $params['month'])?->startOfMonth()
            : null;
        $month ??= Carbon::parse($date)->startOfMonth();

        $today = Carbon::today();
        $countedUntil = $month->copy()->endOfMonth()->gt($today)
            ? $today->toDateString()
            : $month->copy()->endOfMonth()->toDateString();
        $elapsedDays = (int) $month->diffInDays(Carbon::parse($countedUntil)) + 1;

        $allowed = $this->scope->studentIdsFor($actor);

        $activities = BoardingActivity::query()
            ->active()
            ->when(isset($params['activity_id']), fn (Builder $q) => $q->where('id', (int) $params['activity_id']))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'code', 'name']);

        $placements = BoardingPlacement::query()
            ->active()
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('student_id', $allowed ?: [-1]))
            ->when(isset($params['dormitory_id']), fn (Builder $q) => $q->where('dormitory_id', (int) $params['dormitory_id']))
            ->get(['id', 'student_id', 'dormitory_id']);

        $dormitoryIds = $placements->pluck('dormitory_id')->unique()->values()->all();

        $dormitories = BoardingDormitory::query()
            ->whereIn('id', $dormitoryIds ?: [-1])
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'status']);

        $supervisors = $dormitoryIds === []
            ? new Collection
            : BoardingMusyrif::query()
                ->active()
                ->whereIn('dormitory_id', $dormitoryIds)
                ->with('employee:id,full_name')
                ->get(['id', 'employee_id', 'dormitory_id', 'position'])
                ->groupBy('dormitory_id');

        $studentIds = $placements->pluck('student_id')->map(fn (mixed $id): int => (int) $id)->all();
        $dormOf = $placements->pluck('dormitory_id', 'student_id');

        $records = $studentIds === []
            ? new Collection
            : BoardingAttendance::query()
                ->whereIn('student_id', $studentIds)
                ->whereDate('date', $date)
                ->get(['student_id', 'activity_id'])
                ->groupBy(fn (BoardingAttendance $row): int => $dormOf[(int) $row->student_id] ?? 0);

        // Hitungan bulanan per asrama untuk persentase kelengkapan.
        $monthlyCounts = $studentIds === []
            ? new Collection
            : BoardingAttendance::query()
                ->whereIn('student_id', $studentIds)
                ->whereDate('date', '>=', $month->toDateString())
                ->whereDate('date', '<=', $countedUntil)
                ->selectRaw('student_id, COUNT(*) as total')
                ->groupBy('student_id')
                ->get()
                ->groupBy(fn (object $row): int => $dormOf[(int) $row->student_id] ?? 0)
                ->map(fn (Collection $rows): int => (int) $rows->sum('total'));

        $studentsInDorm = $placements->groupBy('dormitory_id')->map->count();

        $activityCount = $activities->count();
        $denominatorPerDay = $activityCount; // 1 baris per (santri, kegiatan, tanggal).

        $rows = $dormitories->map(function (BoardingDormitory $dorm) use (
            $activities, $records, $monthlyCounts, $studentsInDorm, $denominatorPerDay, $elapsedDays, $supervisors
        ): array {
            $targets = (int) ($studentsInDorm[(int) $dorm->id] ?? 0);
            $daily = $records->get((int) $dorm->id, new Collection);
            $perActivity = $daily->groupBy(fn (BoardingAttendance $row): int => (int) $row->activity_id)
                ->map(fn (Collection $rows): int => $rows->unique('student_id')->count());

            $filled = [];
            $partial = [];
            $unfilled = [];

            foreach ($activities as $activity) {
                $recorded = (int) ($perActivity[(int) $activity->id] ?? 0);
                $entry = [
                    'id' => (int) $activity->id,
                    'name' => (string) $activity->name,
                    'targets' => $targets,
                    'recorded' => $recorded,
                ];

                if ($targets === 0 || $recorded === 0) {
                    $unfilled[] = $entry;
                } elseif ($recorded >= $targets) {
                    $filled[] = $entry;
                } else {
                    $partial[] = $entry;
                }
            }

            $expected = $targets * $denominatorPerDay * $elapsedDays;
            $monthly = (int) ($monthlyCounts[(int) $dorm->id] ?? 0);

            return [
                'id' => (int) $dorm->id,
                'code' => (string) $dorm->code,
                'name' => (string) $dorm->name,
                'status' => (string) $dorm->status,
                'supervisors' => ($supervisors[(int) $dorm->id] ?? collect())
                    ->map(fn (BoardingMusyrif $m): string => (string) ($m->employee?->full_name ?? ''))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
                'targets' => $targets,
                'daily_records' => (int) $perActivity->sum(),
                'activities_total' => count($filled) + count($partial) + count($unfilled),
                'filled_activities' => $filled,
                'partial_activities' => $partial,
                'unfilled_activities' => $unfilled,
                'monthly_percentage' => $expected > 0
                    ? round(min(100, ($monthly / $expected) * 100), 1)
                    : null,
            ];
        })->values()->all();

        return [
            'date' => $date,
            'month' => $month->format('Y-m'),
            'activities' => $activities->map(fn (BoardingActivity $a): array => [
                'id' => (int) $a->id,
                'name' => (string) $a->name,
            ])->all(),
            'dormitories' => $rows,
        ];
    }

    /**
     * Statistik absensi untuk dasbor grafik (donat per tipe, tren harian,
     * perbandingan asrama, santri paling sering tidak hadir).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function statistics(array $params, User $actor): array
    {
        $to = $this->date($params, 'to') ?? Carbon::today()->toDateString();
        $from = $this->date($params, 'from') ?? Carbon::parse($to)->subDays(29)->toDateString();

        if ($from > $to) {
            throw ValidationException::withMessages([
                'from' => 'Tanggal mulai tidak boleh setelah tanggal selesai.',
            ]);
        }

        // Batasi rentang agar kueri agregat tetap ringan di perangkat lapangan.
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            throw ValidationException::withMessages([
                'from' => 'Rentang statistik maksimal 366 hari.',
            ]);
        }

        $allowed = $this->scope->studentIdsFor($actor);

        $studentIds = null;
        if (isset($params['dormitory_id'])) {
            $studentIds = $this->placedStudentIds(
                fn (Builder $q) => $q->where('dormitory_id', (int) $params['dormitory_id']),
            );
            if ($allowed !== null) {
                $studentIds = array_values(array_intersect($studentIds, $allowed));
            }
        } elseif ($allowed !== null) {
            $studentIds = $allowed;
        }

        if ($studentIds !== null && $studentIds === []) {
            $studentIds = [-1]; // lingkup kosong → pastikan tak ada baris
        }

        $rows = BoardingAttendance::query()
            ->with([
                'attendanceType:id,code,name,color,is_present',
                'student:id,full_name,nis',
            ])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($studentIds !== null, fn (Builder $q) => $q->whereIn('student_id', $studentIds))
            ->when(isset($params['activity_id']), fn (Builder $q) => $q->where('activity_id', (int) $params['activity_id']))
            ->get(['id', 'student_id', 'activity_id', 'attendance_type_id', 'date', 'is_late']);

        $placements = $this->placementsFor(
            $rows->pluck('student_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all(),
        );

        // --- Distribusi per tipe (donat) ---
        $byType = $rows->groupBy('attendance_type_id')->map(function (Collection $group): array {
            /** @var \App\Models\BoardingAttendanceType|null $type */
            $type = $group->first()->attendanceType;

            return [
                'type_id' => (int) ($type->id ?? 0),
                'code' => (string) ($type->code ?? ''),
                'name' => (string) ($type->name ?? 'Tanpa Tipe'),
                'color' => (string) ($type->color ?? '#94a3b8'),
                'is_present' => (bool) ($type->is_present ?? false),
                'count' => $group->count(),
            ];
        })->sortByDesc('count')->values()->all();

        // --- Tren harian (area/line) ---
        $daily = $rows->groupBy(fn (BoardingAttendance $row): string => Carbon::parse($row->date)->toDateString())
            ->map(function (Collection $group, string $date): array {
                $present = $group->filter(fn (BoardingAttendance $r): bool => (bool) ($r->attendanceType?->is_present) && ! $r->is_late)->count();
                $late = $group->filter(fn (BoardingAttendance $r): bool => (bool) $r->is_late)->count();
                $notPresent = $group->filter(fn (BoardingAttendance $r): bool => ! (bool) ($r->attendanceType?->is_present))->count();

                return [
                    'date' => $date,
                    'total' => $group->count(),
                    'present' => $present,
                    'late' => $late,
                    'not_present' => $notPresent,
                ];
            })->sortKeys()->values()->all();

        // --- Perbandingan asrama (bar) — lewat penempatan AKTIF saat ini ---
        $dormNames = BoardingDormitory::query()
            ->whereIn('id', $rows->map(fn (BoardingAttendance $r): int => (int) ($placements[(int) $r->student_id]?->dormitory_id ?? 0))->filter()->unique()->values()->all() ?: [-1])
            ->pluck('name', 'id');

        $byDorm = $rows->groupBy(function (BoardingAttendance $row) use ($placements): int {
            return (int) ($placements[(int) $row->student_id]?->dormitory_id ?? 0);
        })->map(function (Collection $group, int|string $dormId) use ($dormNames): array {
            $dormName = $dormId > 0
                ? (string) ($dormNames[(int) $dormId] ?? 'Asrama #'.$dormId)
                : 'Tanpa Penempatan';
            $present = $group->filter(fn (BoardingAttendance $r): bool => (bool) ($r->attendanceType?->is_present))->count();
            $late = $group->filter(fn (BoardingAttendance $r): bool => (bool) $r->is_late)->count();

            return [
                'dormitory_id' => (int) $dormId,
                'name' => $dormName,
                'total' => $group->count(),
                'present' => $present,
                'late' => $late,
                'not_present' => $group->count() - $present,
                'present_percent' => $group->count() > 0 ? round(($present / $group->count()) * 100, 1) : 0.0,
            ];
        })->sortBy('name')->values()->all();

        // --- Santri dengan ketidakhadiran terbanyak ---
        $topAbsentees = $rows->filter(fn (BoardingAttendance $r): bool => ! (bool) ($r->attendanceType?->is_present))
            ->groupBy('student_id')
            ->map(function (Collection $group) use ($placements): array {
                $student = $group->first()->student;
                $placement = $placements[(int) $group->first()->student_id] ?? null;

                return [
                    'student_id' => (int) $group->first()->student_id,
                    'full_name' => (string) ($student?->full_name ?? '-'),
                    'nis' => (string) ($student?->nis ?? '-'),
                    'dormitory' => (string) ($placement?->dormitory?->name ?? '-'),
                    'room' => (string) ($placement?->room?->name ?? '-'),
                    'absent_count' => $group->count(),
                ];
            })->sortByDesc('absent_count')->values()->take(10)->all();

        $total = $rows->count();
        $presentTotal = $rows->filter(fn (BoardingAttendance $r): bool => (bool) ($r->attendanceType?->is_present))->count();
        $lateTotal = $rows->filter(fn (BoardingAttendance $r): bool => (bool) $r->is_late)->count();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'summary' => [
                'records' => $total,
                'present' => $presentTotal,
                'late' => $lateTotal,
                'not_present' => $total - $presentTotal,
                'present_percent' => $total > 0 ? round(($presentTotal / $total) * 100, 1) : 0.0,
            ],
            'status_distribution' => $byType,
            'daily' => $daily,
            'by_dormitory' => $byDorm,
            'top_absentees' => $topAbsentees,
        ];
    }

    // =====================================================================
    // TULIS — SATU BARIS
    // =====================================================================

    /**
     * Catat satu absensi manual (§17, §24).
     *
     * @param  array<string, mixed>  $data
     */
    public function store(array $data, User $actor): BoardingAttendance
    {
        $attendance = DB::transaction(
            fn (): BoardingAttendance => $this->record($data, $actor, BoardingAttendance::SOURCE_MANUAL),
        );

        $this->notifyGuarded($attendance);

        return $attendance->load($this->eagerLoads());
    }

    /**
     * Absensi massal dengan semantik UPSERT (§24).
     *
     * "Hadir Semua" mengirim seluruh santri dengan tipe default; koreksi
     * berikutnya mengirim daftar yang sama dengan beberapa tipe berbeda. Baris
     * yang sudah ada DIPERBARUI (bukan ditolak, bukan diduplikasi), baris yang
     * belum ada DIBUAT, dan baris yang tidak berubah dihitung `skipped` supaya
     * petugas tahu tindakannya benar-benar menghasilkan perubahan.
     *
     * Revision tidak dinaikkan di sini: massal adalah kelanjutan layar yang
     * sama, bukan koreksi setelah fact-check (§26). Audit `..._UPDATED` tetap
     * ditulis supaya pergeseran status terbaca.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function bulk(array $data, User $actor): array
    {
        $activity = $this->resolveActivity($data['activity_id'] ?? null);
        $date = $this->date($data, 'date') ?? Carbon::today()->toDateString();
        $session = $this->sessionOf($data['session'] ?? null);

        $items = array_values(array_filter((array) ($data['items'] ?? []), static fn (mixed $row): bool => is_array($row)));

        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'Daftar santri yang diabsen kosong. Pilih minimal satu santri.',
            ]);
        }

        $defaultType = $this->resolveType(
            $data['default_attendance_type_id'] ?? null,
            'default_attendance_type_id',
            $activity,
        );

        $studentIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row['student_id'] ?? 0),
            $items,
        )));

        $students = Student::query()->whereIn('id', $studentIds ?: [-1])->get()->keyBy('id');
        $placements = $this->placementsFor($studentIds);
        $existing = $this->existingFor($activity, $date, $session, $studentIds);

        /** @var array{created: int, updated: int, skipped: int} $count */
        $count = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        /** @var list<BoardingAttendance> $touched */
        $touched = [];

        DB::transaction(function () use (
            $items, $students, $existing, $placements, $activity, $date, $session, $defaultType, $actor,
            &$count, &$touched
        ): void {
            foreach (array_values($items) as $index => $item) {
                $studentId = (int) ($item['student_id'] ?? 0);

                $student = $students->get($studentId);

                if ($student === null) {
                    throw ValidationException::withMessages([
                        "items.$index.student_id" => 'Santri pada baris '.($index + 1).' tidak ditemukan di master siswa.',
                    ]);
                }

                $this->scope->assertCanAccessStudent($actor, $studentId);

                $type = $this->resolveType($item['attendance_type_id'] ?? null, "items.$index.attendance_type_id", $activity, $defaultType);
                $note = $this->nullableText($item['note'] ?? null, 1000);

                $this->assertNoteProvided($type, ['note' => $note], "items.$index.note");

                /** @var BoardingAttendance|null $row */
                $row = $existing->get($studentId);

                if ($row === null) {
                    $created = $this->record([
                        'student_id' => $studentId,
                        'activity_id' => $activity->id,
                        'date' => $date,
                        'session' => $session,
                        'attendance_type_id' => $type->id,
                        'note' => $note,
                    ], $actor, BoardingAttendance::SOURCE_BULK, $placements->get($studentId));

                    $count['created']++;
                    $touched[] = $created;

                    continue;
                }

                if ((int) $row->attendance_type_id === (int) $type->id && (string) $row->note === (string) $note) {
                    $count['skipped']++;

                    continue;
                }

                $before = $this->snapshot($row);

                $row->forceFill([
                    'attendance_type_id' => $type->id,
                    'note' => $note,
                ])->save();

                $count['updated']++;
                $touched[] = $row;

                $this->auditUpdate($row, $before, $this->snapshot($row->refresh()), $actor, 'Perubahan lewat absensi massal.');
            }
        });

        foreach ($touched as $attendance) {
            $this->notifyGuarded($attendance);
        }

        return [
            'activity' => $this->activitySummary($activity),
            'date' => $date,
            'session' => $session,
            'created' => $count['created'],
            'updated' => $count['updated'],
            'skipped' => $count['skipped'],
            'total' => count($items),
            'records' => collect($touched)->map(fn (BoardingAttendance $a): BoardingAttendance => $a->load($this->eagerLoads()))->all(),
        ];
    }

    /**
     * Koreksi satu catatan absensi (§24, §26): nilai berubah, riwayat tidak.
     *
     * `revision` dinaikkan, `revised_by`/`revised_at` diisi, dan sebelum/sesudah
     * ditulis ke `activity_log` supaya "siapa mengubah hadir menjadi alpha,
     * kapan" selalu bisa dijawab.
     *
     * @param  array<string, mixed>  $data
     */
    public function revise(BoardingAttendance $attendance, array $data, User $actor): BoardingAttendance
    {
        $this->scope->assertCanAccessStudent($actor, (int) $attendance->student_id);

        $activity = $attendance->activity ?? $this->resolveActivity($attendance->activity_id);

        $type = $this->resolveType($data['attendance_type_id'] ?? null, 'attendance_type_id', $activity);
        $note = array_key_exists('note', $data) ? $this->nullableText($data['note'], 1000) : $attendance->note;

        $this->assertNoteProvided($type, ['note' => $note], 'note');

        $before = $this->snapshot($attendance);

        if ($before['attendance_type_id'] === (int) $type->id && (string) $before['note'] === (string) $note) {
            throw ValidationException::withMessages([
                'attendance_type_id' => 'Tidak ada perubahan pada catatan absensi ini, sehingga koreksi tidak diperlukan.',
            ]);
        }

        DB::transaction(function () use ($attendance, $type, $note, $actor, $before): void {
            $attendance->forceFill([
                'attendance_type_id' => $type->id,
                'note' => $note,
                'revision' => (int) $attendance->revision + 1,
                'revised_by' => $actor->id,
                'revised_at' => Carbon::now(),
            ])->save();

            $this->auditUpdate($attendance, $before, $this->snapshot($attendance->refresh()), $actor, 'Koreksi manual oleh pengasuh.');
        });

        $fresh = $attendance->refresh()->load($this->eagerLoads());

        $this->notifyGuarded($fresh);

        return $fresh;
    }

    /**
     * Absensi dari scan kartu RFID (§23, §25, §61).
     *
     * Kartu adalah identitas, bukan keyakinan: pemindai hanya tahu "santri ini
     * muncul pada jam kegiatan ini". Karena itu tipe absensinya memakai tipe
     * default kegiatan (pengasuh mengonfirmasi/koreksi belakangan), dan hasil
     * scan yang berulang TIDAK membuat baris kedua — pemindai di gerbang akan
     * dipakai berkali-kali oleh santri yang sama.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function scan(string $uid, array $data, User $actor): array
    {
        if (! (bool) $this->settings->get('rfid_attendance_enabled', true)) {
            throw ValidationException::withMessages([
                'uid' => 'Absensi lewat kartu RFID sedang dinonaktifkan pada pengaturan modul asrama.',
            ]);
        }

        $normalized = WalletRfidService::normalizeUid($uid);

        $card = WalletRfidCard::query()->where('uid', $normalized)->with('wallet.student')->first();

        if ($card === null) {
            throw ValidationException::withMessages([
                'uid' => 'Kartu RFID '.$normalized.' tidak terdaftar pada sistem. Periksa kembali nomor pada badan kartu.',
            ]);
        }

        $this->assertCardUsable($card);

        $student = $this->studentOfCard($card, $normalized);

        return $this->performScan($student, $actor, $data, BoardingAttendance::SOURCE_RFID, [
            'uid' => $card->uid,
            'card_id' => (int) $card->id,
            'status' => $card->status,
            'device' => $this->nullableText($data['device'] ?? null, 50),
        ]);
    }

    /** Santri pemilik kartu RFID — memastikan kartu memang kartu santri ber-data utuh. */
    private function studentOfCard(WalletRfidCard $card, string $normalized): Student
    {
        $wallet = $card->wallet;

        if ($wallet === null || $wallet->ownerType() !== Wallet::TYPE_STUDENT) {
            throw ValidationException::withMessages([
                'uid' => 'Kartu '.$normalized.' bukan milik santri, sehingga tidak dapat dipakai untuk absensi asrama.',
            ]);
        }

        $student = $wallet->student;

        if ($student === null) {
            throw ValidationException::withMessages([
                'uid' => 'Kartu '.$normalized.' menunjuk wallet santri yang datanya sudah tidak ada.',
            ]);
        }

        return $student;
    }

    /**
     * Scan kode QR kamera (§23 varian). Isi kode adalah NIS santri — identitas
     * yang sama dengan yang dicetak pada kartu QR.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function scanQr(string $code, array $data, User $actor): array
    {
        $nis = Str::upper(trim($code));

        if ($nis === '') {
            throw ValidationException::withMessages([
                'code' => 'Kode QR kosong. Coba pindai ulang atau gunakan ketik manual.',
            ]);
        }

        $student = Student::query()->where('nis', $nis)->first();

        if ($student === null) {
            throw ValidationException::withMessages([
                'code' => 'Kode '.$nis.' bukan NIS santri yang dikenal sistem.',
            ]);
        }

        return $this->performScan($student, $actor, $data, BoardingAttendance::SOURCE_QR, [
            'code' => $nis,
            'device' => $this->nullableText($data['device'] ?? null, 50),
        ]);
    }

    /**
     * Inti bersama jalur scan (RFID maupun QR): validasi kartu/santri sudah
     * selesai di pemanggil; di sini yang ditegakkan adalah ruang lingkup,
     * penempatan aktif (§9), kegiatan pada jendela jadwal (§25), dedupelikasi
     * per (santri, kegiatan, tanggal, sesi), pencatatan, audit, notifikasi.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $card
     * @return array<string, mixed>
     */
    private function performScan(
        Student $student,
        User $actor,
        array $data,
        string $source,
        ?array $card,
    ): array {
        $codeLabel = $card['uid'] ?? $card['code'] ?? (string) $student->nis;

        $this->scope->assertCanAccessStudent($actor, (int) $student->id);

        $placement = $this->activePlacement((int) $student->id);

        // Absensi asrama hanya untuk santri yang memang tinggal di asrama SAAT INI.
        // Penempatan lama tetap ada sebagai riwayat (§9), jadi status aktif-lah
        // yang menentukan, bukan "pernah pernah ditempatkan".
        if ($placement === null) {
            throw ValidationException::withMessages([
                'uid' => 'Kartu '.$codeLabel.' atas nama '.(string) $student->full_name
                    .' tidak memiliki penempatan aktif di asrama, sehingga tidak dapat diabsen.',
            ]);
        }

        $date = $this->date($data, 'date') ?? Carbon::today()->toDateString();
        $session = $this->sessionOf($data['session'] ?? null);

        $activity = $this->activityForScan($data['activity_id'] ?? null, $date, $placement);

        $existing = BoardingAttendance::query()
            ->where('student_id', $student->id)
            ->where('activity_id', $activity->id)
            ->whereDate('date', $date)
            ->where('session', $session)
            ->with($this->eagerLoads())
            ->first();

        if ($existing !== null) {
            // Absensi ganda: kembalikan baris yang sudah ada, bukan error — mesin
            // di gerbang harus tetap menampilkan "sudah tercatat".
            return [
                'attendance' => $existing,
                'already_recorded' => true,
                'message' => 'Absensi sudah tercatat',
                'activity' => $this->activitySummary($activity),
                'student' => $this->studentSummary($student),
                'card' => $card,
                'duplicate' => true,
            ];
        }

        $attendance = DB::transaction(function () use ($data, $actor, $activity, $student, $date, $session, $placement, $card, $source, $codeLabel): BoardingAttendance {
            $recorded = $this->record([
                'student_id' => $student->id,
                'activity_id' => $activity->id,
                'date' => $date,
                'session' => $session,
                'attendance_type_id' => $data['attendance_type_id'] ?? null,
                'device' => $data['device'] ?? null,
            ], $actor, $source, $placement);

            activity('boarding')
                ->event('BOARDING_ATTENDANCE_'.strtoupper($source))
                ->performedOn($recorded)
                ->causedBy($actor)
                ->withProperties([
                    'uid' => $card['uid'] ?? null,
                    'code' => $card['code'] ?? null,
                    'card_id' => $card['card_id'] ?? null,
                    'device' => $recorded->device,
                    'student_id' => (int) $student->id,
                    'activity_id' => (int) $activity->id,
                    'date' => $date,
                    'session' => $session,
                    'is_late' => (bool) $recorded->is_late,
                    'late_minutes' => $recorded->late_minutes,
                ])
                ->log(sprintf(
                    'Scan %s %s: %s tercatat absen %s oleh %s.',
                    $source === BoardingAttendance::SOURCE_QR ? 'QR' : 'RFID',
                    $codeLabel,
                    (string) $student->full_name,
                    (string) $activity->name,
                    (string) $actor->name,
                ));

            return $recorded;
        });

        $fresh = $attendance->load($this->eagerLoads());

        $this->notifyGuarded($fresh);

        return [
            'attendance' => $fresh,
            'already_recorded' => false,
            'message' => 'Absensi berhasil dicatat',
            'activity' => $this->activitySummary($activity),
            'student' => $this->studentSummary($student),
            'card' => $card,
            'duplicate' => false,
        ];
    }

    // =====================================================================
    // INTI TULIS
    // =====================================================================

    /**
     * Satu-satunya tempat baris absensi dilahirkan.
     *
     * @param  array<string, mixed>  $data
     */
    private function record(array $data, User $actor, string $source, ?BoardingPlacement $placement = null): BoardingAttendance
    {
        $activity = $this->resolveActivity($data['activity_id'] ?? null);
        $student = $this->resolveStudent($data['student_id'] ?? null);
        $date = $this->date($data, 'date') ?? Carbon::today()->toDateString();
        $session = $this->sessionOf($data['session'] ?? null);

        $this->scope->assertCanAccessStudent($actor, (int) $student->id);

        $type = $this->resolveType($data['attendance_type_id'] ?? null, 'attendance_type_id', $activity);

        $this->assertNoteProvided($type, $data, 'note');

        if (BoardingAttendance::existsFor((int) $student->id, (int) $activity->id, $date, $session)) {
            throw ValidationException::withMessages([
                'attendance' => 'Absensi sudah tercatat untuk kegiatan ini.',
            ]);
        }

        $placement ??= $this->activePlacement((int) $student->id);

        // Absensi asrama hanya untuk santri yang sedang menempati asrama (§24) —
        // termasuk jalur scan RFID: kartu santri non-pesantren ditolak di sini.
        if ($placement === null) {
            throw ValidationException::withMessages([
                'student_id' => 'Santri tidak memiliki penempatan asrama aktif.',
            ]);
        }

        $schedule = $this->scheduleFor($activity, $date, [(int) $student->id], new Collection([$placement]));

        $recordedAt = $this->moment($data['recorded_at'] ?? null);
        $lateness = $this->lateness($schedule, $activity, $date, $recordedAt);

        $attributes = [
            'student_id' => $student->id,
            'activity_id' => $activity->id,
            'schedule_id' => $schedule?->id,
            'attendance_type_id' => $type->id,
            'date' => $date,
            'session' => $session,
            'recorded_at' => $recordedAt,
            'is_late' => $lateness['is_late'],
            'late_minutes' => $lateness['late_minutes'],
            'source' => in_array($source, BoardingAttendance::SOURCES, true) ? $source : BoardingAttendance::SOURCE_MANUAL,
            'device' => $this->nullableText($data['device'] ?? null, 50),
            'note' => $this->nullableText($data['note'] ?? null, 1000),
            'recorded_by' => $actor->id,
            'revision' => 1,
        ];

        try {
            return BoardingAttendance::create($attributes);
        } catch (QueryException $exception) {
            // Constraint unik database menang di atas pemeriksaan "ada belum":
            // dua petugas menekan tombol bersamaan. Pesan yang sampai ke layar
            // harus tetap sama, bukan error 500.
            if ($this->isDuplicate($exception)) {
                throw ValidationException::withMessages([
                    'attendance' => 'Absensi sudah tercatat untuk kegiatan ini.',
                ]);
            }

            throw $exception;
        }
    }

    // =====================================================================
    // TIPE ABSENSI & KEGIATAN
    // =====================================================================

    /**
     * @throws ValidationException
     */
    private function resolveActivity(mixed $id): BoardingActivity
    {
        $activityId = is_numeric($id) ? (int) $id : null;

        $activity = $activityId === null ? null : BoardingActivity::query()->find($activityId);

        if ($activity === null) {
            throw ValidationException::withMessages([
                'activity_id' => 'Kegiatan asrama tidak ditemukan. Pilih kegiatan pada master kegiatan.',
            ]);
        }

        if (! $activity->isActive()) {
            throw ValidationException::withMessages([
                'activity_id' => 'Kegiatan "'.(string) $activity->name.'" sedang tidak aktif sehingga tidak dapat diabsen.',
            ]);
        }

        return $activity;
    }

    /**
     * Tipe absensi yang dipakai.
     *
     * Urutan: nilai permintaan -> tipe cadangan yang diberikan pemanggil (mis.
     * default massal) -> tipe default kegiatan. Setelah itu tipe harus aktif dan
     * termasuk himpunan yang diizinkan pivot kegiatan (§18).
     *
     * @throws ValidationException
     */
    private function resolveType(mixed $id, string $key, BoardingActivity $activity, ?BoardingAttendanceType $fallback = null): BoardingAttendanceType
    {
        $typeId = is_numeric($id) ? (int) $id : null;

        if ($typeId === null && $fallback !== null) {
            $typeId = (int) $fallback->id;
        }

        if ($typeId === null) {
            $typeId = $activity->default_attendance_type_id === null ? null : (int) $activity->default_attendance_type_id;
        }

        if ($typeId === null) {
            throw ValidationException::withMessages([
                $key => 'Tipe absensi belum dipilih dan kegiatan ini tidak punya tipe default. Atur master kegiatan lebih dulu.',
            ]);
        }

        $type = BoardingAttendanceType::query()->find($typeId);

        if ($type === null) {
            throw ValidationException::withMessages([$key => 'Tipe absensi tidak ditemukan pada master.']);
        }

        if (! $type->is_active) {
            throw ValidationException::withMessages([
                $key => 'Tipe absensi "'.(string) $type->name.'" sudah tidak aktif sehingga tidak dapat dipakai.',
            ]);
        }

        $allowed = $this->allowedTypeIds($activity);

        if ($allowed !== [] && ! in_array((int) $type->id, $allowed, true)) {
            throw ValidationException::withMessages([
                $key => 'Tipe absensi "'.(string) $type->name.'" tidak termasuk pilihan untuk kegiatan '
                    .'"'.$activity->name.'". Susun terlebih dahulu pada master kegiatan (§18).',
            ]);
        }

        return $type;
    }

    /**
     * Catatan wajib pada tipe yang memintanya (§17): sakit/izin tanpa keterangan
     * tidak berguna untuk laporan maupun bagi wali yang diberitahu.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNoteProvided(BoardingAttendanceType $type, array $data, string $key): void
    {
        if (! $type->requiresNote()) {
            return;
        }

        if (blank($data['note'] ?? null)) {
            throw ValidationException::withMessages([
                $key => 'Tipe absensi "'.(string) $type->name.'" wajib diberi keterangan.',
            ]);
        }
    }

    /**
     * @return list<int>  kosong = kegiatan belum dikonfigurasi (semua tipe aktif sah)
     */
    private function allowedTypeIds(BoardingActivity $activity): array
    {
        $key = (int) $activity->id;

        if (isset($this->allowedTypeCache[$key])) {
            return $this->allowedTypeCache[$key];
        }

        $ids = $activity->attendanceTypes()
            ->pluck('boarding_attendance_types.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $default = $activity->default_attendance_type_id === null ? null : (int) $activity->default_attendance_type_id;

        if ($default !== null && ! in_array($default, $ids, true)) {
            $ids[] = $default;
        }

        return $this->allowedTypeCache[$key] = $ids;
    }

    // =====================================================================
    // JADWAL & KETERLAMBATAN (§25)
    // =====================================================================

    /**
     * Jadwal yang berlaku untuk satu kelompok santri pada satu tanggal.
     *
     * Kandidatnya: aktif, efektif pada tanggal itu, dan hari-nya cocok. Bila
     * beberapa kandidat ada, yang TERSEMPIT yang dipakai — jadwal per kelompok
     * mengalahkan per asrama, dan itu mengalahkan jadwal global — karena halaqah
     * kelompok jelas mulai lebih siang daripada jamaah asrama.
     *
     * @param  list<int>  $studentIds
     * @param  Collection<int, BoardingPlacement>  $placements
     */
    private function scheduleFor(
        BoardingActivity $activity,
        string $date,
        array $studentIds = [],
        ?Collection $placements = null,
    ): ?BoardingActivitySchedule {
        $day = $this->dayOfWeekIso($date);

        if ($day === null) {
            return null;
        }

        $candidates = BoardingActivitySchedule::query()
            ->with('activity')
            ->effectiveOn($date)
            ->onDay($day)
            ->where('activity_id', (int) $activity->id)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $dormitoryIds = [];
        $groupIds = [];

        foreach ($placements ?? new Collection as $placement) {
            if ($placement instanceof BoardingPlacement && $placement->dormitory_id !== null) {
                $dormitoryIds[] = (int) $placement->dormitory_id;
            }
        }

        if ($studentIds !== []) {
            $groupIds = BoardingGroupMember::query()
                ->current()
                ->whereIn('student_id', $studentIds)
                ->pluck('group_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        $dormitoryIds = array_values(array_unique($dormitoryIds));

        $applicable = $candidates->filter(static function (BoardingActivitySchedule $schedule) use ($dormitoryIds, $groupIds): bool {
            $scopeDorm = $schedule->dormitory_id === null ? null : (int) $schedule->dormitory_id;
            $scopeGroup = $schedule->group_id === null ? null : (int) $schedule->group_id;

            if ($scopeGroup !== null && ! in_array($scopeGroup, $groupIds, true)) {
                return false;
            }

            return $scopeDorm === null || $dormitoryIds === [] || in_array($scopeDorm, $dormitoryIds, true);
        });

        /** @var Collection<int, BoardingActivitySchedule> $applicable */
        $applicable = $applicable->filter(static fn (BoardingActivitySchedule $s): bool => $s->activity?->isActive() ?? false);

        if ($applicable->isEmpty()) {
            return null;
        }

        // Spesifisitas: kelompok (3) > asrama (2) > global (1).
        return $applicable->sortByDesc(static function (BoardingActivitySchedule $schedule) use ($groupIds): int {
            if ($schedule->group_id !== null && in_array((int) $schedule->group_id, $groupIds, true)) {
                return 3;
            }

            return $schedule->dormitory_id === null ? 1 : 2;
        })->first();
    }

    /**
     * Ambang telat = jam mulai + `late_after_minutes` kegiatan.
     *
     * `late_minutes` sengaja dihitung DARI JAM MULAI, bukan dari ambangnya:
     * laporan berbicara "terlambat 15 menit", dan ambang hanya penentu
     * YA/TIDAK. Tanpa jadwal atau tanpa ambang, is_late tetap false — sistem
     * tidak mengarang angka (§25).
     *
     * @return array{is_late: bool, late_minutes: int|null, cutoff: string|null, start: string|null}
     */
    private function lateness(
        ?BoardingActivitySchedule $schedule,
        BoardingActivity $activity,
        string $date,
        Carbon $recordedAt,
    ): array {
        $result = ['is_late' => false, 'late_minutes' => null, 'cutoff' => null, 'start' => null];

        if ($schedule === null || ! $activity->hasLateThreshold()) {
            return $result;
        }

        $start = $this->momentOf($this->rawTime($schedule, 'start_time'), $date);
        $cutoff = $this->momentOf($this->rawTime($schedule, 'end_time'), $date);

        if ($start === null) {
            return $result;
        }

        $threshold = $start->copy()->addMinutes((int) $activity->late_after_minutes);

        $result['start'] = $start->toDateTimeString();
        $result['cutoff'] = $threshold->toDateTimeString();

        if ($recordedAt->lt($threshold)) {
            return $result;
        }

        $result['is_late'] = true;
        $result['late_minutes'] = max(0, (int) $start->diffInMinutes($recordedAt, false));

        return $result;
    }

    // =====================================================================
    // SCAN RFID
    // =====================================================================

    /** Kartu mati tidak pernah mengidentifikasi siapa pun (§6). */
    private function assertCardUsable(WalletRfidCard $card): void
    {
        if (in_array($card->status, WalletRfidCard::DEAD_STATUSES, true)) {
            $label = match ($card->status) {
                WalletRfidCard::STATUS_LOST => 'dilaporkan hilang',
                WalletRfidCard::STATUS_BLOCKED => 'diblokir',
                WalletRfidCard::STATUS_REPLACED => 'sudah diganti dengan kartu baru',
                default => (string) $card->status,
            };

            throw ValidationException::withMessages([
                'uid' => 'Kartu '.$card->uid.' '.$label.' sehingga tidak dapat dipakai absensi. '
                    .'Silakan gunakan kartu aktif atau laporkan ke petugas wallet.',
            ]);
        }

        if ($card->status !== WalletRfidCard::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'uid' => 'Kartu '.$card->uid.' sedang tidak aktif (status '.$card->status.'), sehingga absensi tidak dapat dicatat.',
            ]);
        }
    }

    /**
     * Kegiatan yang sedang berlangsung, bila pemindai tidak mengirimnya.
     *
     * Jendelanya `start_time - 60 menit` s.d. `end_time`. Tepat satu kandidat →
     * dipakai; nol atau lebih dari satu → minta petugas memilih. Menaksir dalam
     * keadaan ambigu berarti salah mencatat absensi santri.
     *
     * @throws ValidationException
     */
    private function activityForScan(mixed $requested, string $date, ?BoardingPlacement $placement): BoardingActivity
    {
        if ($requested !== null && $requested !== '') {
            return $this->resolveActivity($requested);
        }

        $day = $this->dayOfWeekIso($date);

        if ($day === null) {
            throw ValidationException::withMessages(['activity_id' => 'Tanggal scan tidak sah sehingga kegiatan tidak dapat ditentukan.']);
        }

        $groupIds = $placement === null ? [] : BoardingGroupMember::query()
            ->current()
            ->where('student_id', $placement->student_id)
            ->pluck('group_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $now = Carbon::now();

        $candidates = BoardingActivitySchedule::query()
            ->with('activity')
            ->effectiveOn($date)
            ->onDay($day)
            ->whereHas('activity', fn (Builder $q) => $q->where('is_active', true))
            ->get()
            ->filter(function (BoardingActivitySchedule $schedule) use ($date, $now, $placement, $groupIds): bool {
                $scopeDorm = $schedule->dormitory_id === null ? null : (int) $schedule->dormitory_id;
                $scopeGroup = $schedule->group_id === null ? null : (int) $schedule->group_id;

                if ($scopeGroup !== null && ! in_array($scopeGroup, $groupIds, true)) {
                    return false;
                }

                if ($scopeDorm !== null && (int) ($placement?->dormitory_id ?? 0) !== $scopeDorm) {
                    return false;
                }

                $start = $this->momentOf($this->rawTime($schedule, 'start_time'), $date);
                $end = $this->momentOf($this->rawTime($schedule, 'end_time'), $date);

                if ($start === null) {
                    return false;
                }

                $from = $start->copy()->subMinutes(self::SCAN_WINDOW_BEFORE_MINUTES);
                $until = $end ?? $start->copy()->addHour();

                return $now->greaterThanOrEqualTo($from) && $now->lessThanOrEqualTo($until);
            })
            // Satu kegiatan boleh punya beberapa slot pada hari yang sama; yang
            // menentukan ambiguitas adalah KEGIATANNYA, bukan jumlah barisnya.
            ->unique(fn (BoardingActivitySchedule $schedule): int => (int) $schedule->activity_id)
            ->values();

        if ($candidates->count() === 1) {
            $schedule = $candidates->first();
            $activity = $schedule?->activity;

            if ($activity instanceof BoardingActivity) {
                return $this->resolveActivity($activity->id);
            }
        }

        throw ValidationException::withMessages([
            'activity_id' => $candidates->isEmpty()
                ? 'Tidak ada kegiatan asrama yang sedang berlangsung pada jam ini. Pilih kegiatan terlebih dahulu.'
                : 'Ada '.count($candidates).' kegiatan yang sedang berlangsung. Kirim activity_id agar absensi tidak salah kegiatan.',
        ]);
    }

    // =====================================================================
    // SANTRI & PENEMPATAN
    // =====================================================================

    /**
     * @throws ValidationException
     */
    private function resolveStudent(mixed $id): Student
    {
        $studentId = is_numeric($id) ? (int) $id : null;

        $student = $studentId === null ? null : Student::query()->find($studentId);

        if ($student === null) {
            throw ValidationException::withMessages([
                'student_id' => 'Santri tidak ditemukan pada master siswa.',
            ]);
        }

        return $student;
    }

    private function activePlacement(int $studentId): ?BoardingPlacement
    {
        return BoardingPlacement::query()
            ->active()
            ->forStudent($studentId)
            ->with(['dormitory:id,code,name', 'room:id,code,name,dormitory_id', 'bed:id,room_id,bed_number'])
            ->latest('id')
            ->first();
    }

    /**
     * @param  list<int>  $studentIds
     * @return Collection<int, BoardingPlacement> keyed by student_id
     */
    private function placementsFor(array $studentIds): Collection
    {
        if ($studentIds === []) {
            return new Collection;
        }

        return BoardingPlacement::query()
            ->active()
            ->whereIn('student_id', $studentIds)
            ->with(['dormitory:id,code,name', 'room:id,code,name,dormitory_id', 'bed:id,room_id,bed_number'])
            ->orderBy('id')
            ->get()
            ->keyBy('student_id');
    }

    /**
     * Santri target roster (§7): penempatan aktif (kamar/asrama) atau anggota
     * kelompok, lalu dipotong hak akses pengasuh (§54).
     *
     * `group_id` bersama kamar/asrama diiris (intersection): halaqah di satu
     * kamar memang irisan dua syarat itu.
     *
     * @param  array<string, mixed>  $params
     * @return list<int>
     */
    private function targetStudentIds(User $actor, array $params): array
    {
        $groupId = $this->integer($params, 'group_id');
        $roomId = $this->integer($params, 'room_id');
        $dormitoryId = $this->integer($params, 'dormitory_id');

        $ids = null;

        if ($groupId !== null) {
            if (BoardingGroup::query()->whereKey($groupId)->doesntExist()) {
                throw ValidationException::withMessages([
                    'group_id' => 'Kelompok pengasuhan tidak ditemukan.',
                ]);
            }

            $ids = $this->groupStudentIds($groupId);
        }

        if ($roomId !== null || $dormitoryId !== null) {
            $placed = $this->placedStudentIds(fn (Builder $q) => $q
                ->when($roomId !== null, fn (Builder $r) => $r->forRoom($roomId))
                ->when($dormitoryId !== null, fn (Builder $d) => $d->forDormitory($dormitoryId)));

            $ids = $ids === null ? $placed : array_values(array_intersect($ids, $placed));
        }

        // Tanpa penyaring lokasi: seluruh santri berpenempatan aktif, tetap
        // terpotong scope pada langkah terakhir.
        $ids ??= $this->placedStudentIds(fn (Builder $q) => $q);

        $allowed = $this->scope->studentIdsFor($actor);

        if ($allowed !== null) {
            $ids = array_values(array_intersect($ids, $allowed));
        }

        return array_values(array_unique(array_map(intval(...), $ids)));
    }

    /**
     * @param  callable(Builder<BoardingPlacement>): void  $narrow
     * @return list<int>
     */
    private function placedStudentIds(callable $narrow): array
    {
        return BoardingPlacement::query()
            ->active()
            ->tap($narrow)
            ->pluck('student_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function groupStudentIds(int $groupId): array
    {
        return BoardingGroupMember::query()
            ->current()
            ->where('group_id', $groupId)
            ->pluck('student_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, BoardingAttendance> keyed by student_id
     */
    private function existingFor(BoardingActivity $activity, string $date, string $session, array $studentIds): Collection
    {
        if ($studentIds === []) {
            return new Collection;
        }

        return BoardingAttendance::query()
            ->with('attendanceType')
            ->where('activity_id', $activity->id)
            ->whereDate('date', $date)
            ->where('session', $session)
            ->whereIn('student_id', $studentIds)
            ->get()
            ->keyBy('student_id');
    }

    // =====================================================================
    // NOTIFIKASI ORANG TUA (§56)
    // =====================================================================

    /**
     * Kabari wali untuk absensi ALPHA / SAKIT — sekali per baris absensi.
     *
     * Dedupenya memakai `reference_id` baris ini (kunci uniknya sama dengan
     * "santri + kegiatan + tanggal + sesi"), jadi pengulangan aksi, scan berkali-
     * kali, dan koreksi berulang tidak menumpuk pesan di kotak masuk wali.
     */
    private function notifyGuarded(BoardingAttendance $attendance): void
    {
        $type = $attendance->attendanceType
            ?? BoardingAttendanceType::query()->find($attendance->attendance_type_id);

        $notificationType = $type === null ? null : $this->notificationTypeFor($type);

        if ($notificationType === null) {
            return;
        }

        $student = $attendance->student ?? Student::query()->find($attendance->student_id);
        $activity = $attendance->activity ?? BoardingActivity::query()->find($attendance->activity_id);

        if ($student === null || $activity === null) {
            return;
        }

        $category = $notificationType === self::NOTIFY_SICK ? 'sakit' : 'tanpa keterangan (alpha)';

        $this->notifications->toParentsOf(
            (int) $student->id,
            $notificationType,
            $notificationType === self::NOTIFY_SICK ? 'Ananda tercatat sakit pada kegiatan asrama' : 'Ananda tidak hadir tanpa keterangan',
            sprintf(
                '%s tercatat %s pada kegiatan %s, tanggal %s sesi %s%s.',
                (string) $student->full_name,
                (string) $type->name,
                (string) $activity->name,
                (string) $attendance->date?->format('d-m-Y'),
                (string) $attendance->session,
                (bool) $attendance->is_late ? ' (terlambat '.$attendance->late_minutes.' menit)' : '',
            ),
            [
                'reference_type' => BoardingAttendance::class,
                'reference_id' => (int) $attendance->id,
                'student_id' => (int) $student->id,
                'activity_id' => (int) $activity->id,
                'date' => (string) $attendance->date?->toDateString(),
                'session' => (string) $attendance->session,
                'attendance_category' => $category,
                'route' => ['name' => 'boarding.attendances.show', 'params' => ['attendance' => (int) $attendance->id]],
            ],
        );
    }

    /**
     * Kategori mana yang pantas mengabari wali?
     *
     * "Sakit" bukan kategori di master — ia tipe berkode SAKIT pada kategori
     * `excused` — jadi kodenya ikut dibaca, sementara semua tanpa keterangan
     * (`unexcused`) dianggap alpha apa pun labelnya sekolah.
     */
    private function notificationTypeFor(BoardingAttendanceType $type): ?string
    {
        $code = Str::upper(trim((string) $type->code));
        $name = Str::lower((string) $type->name);

        if ($code === BoardingAttendanceType::CODE_SAKIT || str_contains($name, 'sakit')) {
            return self::NOTIFY_SICK;
        }

        if ($type->category === BoardingAttendanceType::CATEGORY_UNEXCUSED) {
            return self::NOTIFY_ALPHA;
        }

        return null;
    }

    // =====================================================================
    // AUDIT (§24, §26, §57)
    // =====================================================================

    /**
     * @return array<string, mixed>
     */
    private function snapshot(BoardingAttendance $attendance): array
    {
        return [
            'attendance_type_id' => (int) $attendance->attendance_type_id,
            'attendance_type' => $attendance->attendanceType?->name,
            'attendance_code' => $attendance->attendanceType?->code,
            'note' => $attendance->note,
            'is_late' => (bool) $attendance->is_late,
            'late_minutes' => $attendance->late_minutes,
            'revision' => (int) $attendance->revision,
            'source' => $attendance->source,
        ];
    }

    /** Sebelum/sesudah lengkap — pertanyaan "siapa mengubah apa" harus terjawab. */
    private function auditUpdate(
        BoardingAttendance $attendance,
        array $before,
        array $after,
        User $actor,
        string $reason,
    ): void {
        activity('boarding')
            ->event('BOARDING_ATTENDANCE_UPDATED')
            ->performedOn($attendance)
            ->causedBy($actor)
            ->withProperties([
                'before' => $before,
                'after' => $after,
                'student_id' => (int) $attendance->student_id,
                'activity_id' => (int) $attendance->activity_id,
                'date' => (string) $attendance->date?->toDateString(),
                'session' => (string) $attendance->session,
                'reason' => $reason,
            ])
            ->log(sprintf(
                'Absensi %s (%s) diubah oleh %s: %s.',
                (string) $attendance->student?->full_name,
                (string) $attendance->activity?->name,
                (string) $actor->name,
                $reason,
            ));
    }

    // =====================================================================
    // PEMBENTUK TAMPILAN
    // =====================================================================

    /** @return list<string> */
    public function eagerLoads(): array
    {
        return [
            'student:id,full_name,nis,nisn',
            'activity:id,code,name,category,late_after_minutes,default_attendance_type_id,is_active',
            'attendanceType:id,code,name,category,is_present,is_excused,requires_note,color',
            'schedule:id,activity_id,day_of_week,start_time,end_time',
            'recordedBy:id,name',
            'revisedBy:id,name',
        ];
    }

    /** @return array<string, mixed> */
    private function rosterRow(Student $student, ?BoardingPlacement $placement, ?BoardingAttendance $attendance): array
    {
        return [
            'student' => $this->studentSummary($student),
            'placement' => $this->placementSummary($placement),
            'already_recorded' => $attendance !== null,
            'attendance' => $attendance === null ? null : [
                'id' => (int) $attendance->id,
                'attendance_type_id' => (int) $attendance->attendance_type_id,
                'attendance_type' => $attendance->attendanceType?->name,
                'attendance_code' => $attendance->attendanceType?->code,
                'category' => $attendance->attendanceType?->category,
                'status_label' => $this->statusLabel($attendance),
                'is_present' => (bool) $attendance->attendanceType?->is_present,
                'is_late' => (bool) $attendance->is_late,
                'late_minutes' => $attendance->late_minutes,
                'note' => $attendance->note,
                'source' => $attendance->source,
                'recorded_at' => $attendance->recorded_at?->toIso8601String(),
                'revision' => (int) $attendance->revision,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function studentSummary(Student $student): array
    {
        return [
            'id' => (int) $student->id,
            'full_name' => $student->full_name,
            'nis' => $student->nis,
        ];
    }

    /** @return array<string, mixed> */
    private function placementSummary(?BoardingPlacement $placement): array
    {
        if ($placement === null) {
            return [
                'id' => null,
                'dormitory_id' => null,
                'dormitory' => null,
                'room_id' => null,
                'room' => null,
                'bed' => null,
                'label' => null,
                'is_placed' => false,
            ];
        }

        return [
            'id' => (int) $placement->id,
            'dormitory_id' => $placement->dormitory_id === null ? null : (int) $placement->dormitory_id,
            'dormitory' => $placement->dormitory?->name,
            'room_id' => $placement->room_id === null ? null : (int) $placement->room_id,
            'room' => $placement->room?->code,
            'bed' => $placement->bed?->bed_number,
            'label' => $placement->label(),
            'is_placed' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function activitySummary(BoardingActivity $activity): array
    {
        return [
            'id' => (int) $activity->id,
            'code' => $activity->code,
            'name' => $activity->name,
            'category' => $activity->category,
            'category_label' => $activity->categoryLabel(),
            'late_after_minutes' => $activity->late_after_minutes,
            'is_required' => (bool) $activity->is_required,
        ];
    }

    /** @return array<string, mixed>|null */
    private function scheduleSummary(?BoardingActivitySchedule $schedule, BoardingActivity $activity): ?array
    {
        if ($schedule === null) {
            return null;
        }

        return [
            'id' => (int) $schedule->id,
            'day_of_week' => (int) $schedule->day_of_week,
            'day_label' => $schedule->dayLabel(),
            'start_time' => $this->hhmm($this->rawTime($schedule, 'start_time')),
            'end_time' => $this->hhmm($this->rawTime($schedule, 'end_time')),
            'late_cutoff' => $activity->hasLateThreshold()
                ? $this->hhmm($this->plusMinutes($this->rawTime($schedule, 'start_time'), (int) $activity->late_after_minutes))
                : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function availableTypes(BoardingActivity $activity): array
    {
        $types = $this->allowedTypeIds($activity) === []
            ? BoardingAttendanceType::query()->active()->orderBy('id')->get()
            : $activity->attendanceTypes()->get();

        return $types->map(static fn (BoardingAttendanceType $type): array => [
            'id' => (int) $type->id,
            'code' => $type->code,
            'name' => $type->name,
            'category' => $type->category,
            'category_label' => $type->categoryLabel(),
            'is_present' => (bool) $type->is_present,
            'is_excused' => (bool) $type->is_excused,
            'requires_note' => (bool) $type->requires_note,
            'color' => $type->color,
        ])->all();
    }

    private function statusLabel(BoardingAttendance $attendance): string
    {
        $label = (string) ($attendance->attendanceType?->name ?? '-');

        return $attendance->is_late ? $label.' (Terlambat)' : $label;
    }

    private function isLateRow(BoardingAttendance $attendance): bool
    {
        return (bool) $attendance->is_late
            || $attendance->attendanceType?->category === BoardingAttendanceType::CATEGORY_LATE;
    }

    // =====================================================================
    // UTILITAS
    // =====================================================================

    /** @param array<string, mixed> $source */
    private function integer(array $source, string $key): ?int
    {
        $value = $source[$key] ?? null;

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $source */
    private function text(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $source */
    private function date(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $value = $this->text($source, $key);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $key => 'Tanggal tidak dikenali. Gunakan format YYYY-MM-DD.',
            ]);
        }
    }

    /** @param array<string, mixed> $source */
    private function boolean(array $source, string $key): ?bool
    {
        $value = $source[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(Str::lower((string) $value), ['1', 'true', 'ya', 'on'], true);
    }

    private function sessionOf(mixed $session): string
    {
        $value = trim((string) ($session ?? ''));

        return $value === '' ? BoardingAttendance::DEFAULT_SESSION : Str::limit($value, 20, '');
    }

    private function moment(mixed $value): Carbon
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return Carbon::now();
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'recorded_at' => 'Waktu pencatatan tidak dikenali.',
            ]);
        }
    }

    /** Jam dari kolom `time` — dibaca MENTAH agar tidak bergantung format cast. */
    private function rawTime(BoardingActivitySchedule $schedule, string $column): ?string
    {
        $raw = $schedule->getRawOriginal($column);

        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        if ($raw instanceof DateTimeInterface) {
            return $raw->format('H:i:s');
        }

        $value = $schedule->getAttribute($column);

        return $value instanceof DateTimeInterface ? $value->format('H:i:s') : null;
    }

    /** "05:00", "05:00:00", atau "2026-09-28 05:00:00" pada tanggal tertentu. */
    private function momentOf(?string $time, string $date): ?Carbon
    {
        $time = $this->hhmmss($time);

        if ($time === null) {
            return null;
        }

        try {
            return Carbon::parse($date.' '.$time);
        } catch (Throwable) {
            return null;
        }
    }

    private function hhmmss(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return preg_match('/(\d{1,2}:\d{2}(?::\d{2})?)\s*$/', $value, $matches) === 1 ? $matches[1] : null;
    }

    private function hhmm(?string $value): ?string
    {
        $time = $this->hhmmss($value);

        return $time === null ? null : substr($time, 0, 5);
    }

    private function plusMinutes(?string $time, int $minutes): ?string
    {
        $seconds = $this->hhmmss($time);

        if ($seconds === null) {
            return null;
        }

        try {
            return Carbon::parse('2000-01-01 '.$seconds)->addMinutes($minutes)->format('H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function dayOfWeekIso(string $date): ?int
    {
        try {
            return Carbon::parse($date)->dayOfWeekIso;
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $max, '');
    }

    /** Penanda unique-constraint di kedua mesin (MySQL 1062 / SQLite 19, 2067). */
    private function isDuplicate(QueryException $exception): bool
    {
        $code = (int) ($exception->errorInfo[1] ?? $exception->getCode());

        return $code === 1062
            || $code === 23000
            || str_contains((string) $exception->getMessage(), 'UNIQUE constraint failed');
    }
}
