<?php

namespace App\Services\Boarding;

use App\Models\BoardingDisciplinaryAction;
use App\Models\BoardingViolation;
use App\Models\BoardingViolationType;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kedisiplinan asrama (§35–§38): master jenis pelanggaran, pencatatan
 * pelanggaran, dan tindak lanjut pendisiplinan.
 *
 * ## Kenapa poin disalin, bukan dihitung dari master
 *
 * `boarding_violations.points` adalah SALINAN poin master pada saat kejadian
 * (§36). Kepala asrama bebas mengubah bobot `boarding_violation_types.point`
 * tanpa membuat total poin historis seorang santri berubah di belakang
 * laporannya. Konsekuensinya: setiap recalcululation dibaca dari kolom
 * `points` itu sendiri, bukan dari JOIN ke master.
 *
 * ## Kebijakan ambang poin (§37, §38)
 *
 * Tiga ambang dibaca dari `BoardingSettingsService` (bukan hard-code, supaya
 * kepala asrama bisa menyetelnya lewat Settings):
 *
 * - `point_threshold_warning`            → tindakan `warning`
 * - `point_threshold_coaching`           → tindakan `coaching`
 * - `point_threshold_parent_notification`→ tindakan `parent_notification`
 *
 * Ambang dinilai sebagai PENAMPAKAN (crossing), bukan keadaan: tindakan baru
 * dibuat hanya bila `total_sebelum < ambang <= total_sesudah`. Dengan begitu
 * empat pelanggaran kecil setelah ambang 25 tidak menghasilkan empat surat
 * teguran, dan satu pelanggaran besar yang melompati dua ambang sekaligus
 * menghasilkan dua tindakan (25 dan 50) — persis yang diinginkan petugas.
 *
 * Karena total poin santri hanya bisa naik lewat pelanggaran, satu ambang
 * hanya terlewat satu kali; `notes` tindakan otomatis dipakai sebagai kunci
 * idempotensi supaya pemanggilan berulang (mis. satu transaksi di-retry) tidak
 * menumpuk baris yang sama.
 *
 * ## Jejak audit
 *
 * Tidak ada panggilan `activity()` eksplisit di sini: seluruh model boarding
 * memakai `LogsBoardingActivity` (`logAll()`), sehingga create/update/delete
 * otomatis tercatat sebagai BOARDING_VIOLATION_CREATED / _UPDATED / _DELETED
 * (nama aksinya dirakit `AppServiceProvider::boot()`), dengan `causer` akun
 * yang sedang login.
 */
class BoardingViolationService
{
    /** Kunci settings => jenis tindakan yang lahir saat ambangnya dilewati. */
    public const THRESHOLD_ACTIONS = [
        'point_threshold_warning' => BoardingDisciplinaryAction::ACTION_WARNING,
        'point_threshold_coaching' => BoardingDisciplinaryAction::ACTION_COACHING,
        'point_threshold_parent_notification' => BoardingDisciplinaryAction::ACTION_PARENT_NOTIFICATION,
    ];

    /** Permission antrean kerja petugas yang ikut menerima kabar ambang poin. */
    public const STAFF_PERMISSION = 'boarding.violation.manage';

    /** Batas baris "recent" pada endpoint poin; angka pilihan UI, bukan bisnis. */
    public const RECENT_LIMIT = 10;

    public function __construct(
        protected readonly BoardingScopeService $scope,
        protected readonly BoardingNotificationService $notifications,
        protected readonly BoardingSettingsService $settings,
    ) {
    }

    // =====================================================================
    // MASTER JENIS PELANGGARAN (§35)
    // =====================================================================

    /**
     * @param  array<string, mixed>  $filters
     */
    public function typeQuery(array $filters = []): Builder
    {
        return BoardingViolationType::query()
            ->ofCategory($filters['category'] ?? null)
            ->ofSeverity($filters['severity'] ?? null)
            ->when(
                isset($filters['is_active']) && $filters['is_active'] !== '' && $filters['is_active'] !== null,
                fn (Builder $q) => $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)),
            )
            ->when(
                filled($filters['keyword'] ?? null),
                fn (Builder $q) => $q->where(
                    fn (Builder $inner) => $inner->where('name', 'like', '%'.$filters['keyword'].'%')
                        ->orWhere('code', 'like', '%'.$filters['keyword'].'%'),
                ),
            )
            ->orderBy('category')
            ->orderBy('code');
    }

    /**
     * @return Collection<int, BoardingViolationType>
     */
    public function types(array $filters = []): Collection
    {
        return $this->typeQuery($filters)->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createType(array $data, ?User $actor = null): BoardingViolationType
    {
        $payload = $this->typePayload($data);

        $this->assertCodeAvailable((string) ($payload['code'] ?? ''), null);

        return DB::transaction(fn (): BoardingViolationType => BoardingViolationType::create($payload));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateType(BoardingViolationType $type, array $data, ?User $actor = null): BoardingViolationType
    {
        $payload = $this->typePayload($data);

        if (isset($payload['code']) && (string) $payload['code'] !== (string) $type->code) {
            $this->assertCodeAvailable((string) $payload['code'], $type->id);
        }

        DB::transaction(fn (): bool => (bool) $type->update($payload));

        return $type->refresh();
    }

    /**
     * Master tidak boleh dihapus selama masih ada catatan yang memakainya:
     * `boarding_violations.violation_type_id` ber-FK `restrictOnDelete`, dan
     * menghapusnya akan memutus riwayat poin santri.
     */
    public function deleteType(BoardingViolationType $type): void
    {
        $used = $type->violations()->count();

        if ($used > 0) {
            throw ValidationException::withMessages([
                'violation_type' => "Jenis pelanggaran ini masih dipakai oleh {$used} catatan pelanggaran. Nonaktifkan saja (is_active = false) agar riwayat poin santri tetap terbaca.",
            ]);
        }

        $type->delete();
    }

    /**
     * Hanya master aktif yang boleh dicatat — pelanggaran pada jenis nonaktif
     * adalah data hantu yang tidak akan muncul di dropdown petugas lain.
     *
     * @param  bool  $required  false = validasi longgar untuk update tanpa tipe
     */
    public function findType(mixed $id, bool $allowInactive = false): BoardingViolationType
    {
        $type = BoardingViolationType::query()->find($id);

        if ($type === null) {
            throw ValidationException::withMessages([
                'violation_type_id' => 'Jenis pelanggaran tidak ditemukan.',
            ]);
        }

        if (! $allowInactive && ! $type->isActive()) {
            throw ValidationException::withMessages([
                'violation_type_id' => 'Jenis pelanggaran sudah tidak aktif. Aktifkan kembali bila memang masih dipakai.',
            ]);
        }

        return $type;
    }

    // =====================================================================
    // PENCATATAN PELANGGARAN (§36)
    // =====================================================================

    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters = [], ?User $actor = null): Builder
    {
        $allowed = $this->scope->studentIdsFor($actor);

        return BoardingViolation::query()
            ->with(['student:id,full_name,nis', 'type:id,code,name,category,point,severity'])
            ->forStudent($this->studentFilter($filters['student_id'] ?? null))
            ->ofType(filled($filters['violation_type_id'] ?? null) ? (int) $filters['violation_type_id'] : null)
            ->between($filters['from'] ?? null, $filters['to'] ?? null)
            ->when(
                filled($filters['status'] ?? null),
                fn (Builder $q) => $q->whereIn('status', (array) $filters['status']),
            )
            ->when(
                filled($filters['category'] ?? null),
                fn (Builder $q) => $q->whereHas('type', fn (Builder $t) => $t->where('category', $filters['category'])),
            )
            // Santri binaan pengguna yang dibatasi tidak boleh dilewati, bahkan
            // bila klien mengirim student_id milik orang lain.
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('student_id', $allowed ?: [0]))
            ->orderByDesc('date')
            ->orderByDesc('id');
    }

    /**
     * Catat satu pelanggaran + jalankan kebijakan ambang poin.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: BoardingViolation, 1: list<BoardingDisciplinaryAction>}
     */
    public function record(array $data, ?User $actor = null): array
    {
        $student = $this->assertStudent($data['student_id'] ?? null, $actor);
        $type = $this->findType($data['violation_type_id'] ?? null);

        /** @var BoardingViolation $violation */
        /** @var list<BoardingDisciplinaryAction> $actions */
        [$violation, $actions] = DB::transaction(function () use ($data, $student, $type, $actor): array {
            $before = $this->totalPointsFor((int) $student->id);

            $violation = BoardingViolation::create([
                'student_id' => $student->id,
                'violation_type_id' => $type->id,
                'date' => $data['date'] ?? now()->toDateString(),
                'narrative' => $this->text($data['narrative'] ?? null),
                // Snapshot bobot master pada saat kejadian (§36).
                'points' => (int) $type->point,
                'evidence_path' => $this->text($data['evidence_path'] ?? null),
                'status' => BoardingViolation::STATUS_OPEN,
                'recorded_by' => $actor?->id,
            ]);

            return [$violation, $this->applyThresholds((int) $student->id, $before, $actor)];
        });

        $this->loadMissingForNotification($violation);

        // Notifikasi sengaja di luar transaksi: kanal `database` menulis ke
        // `notifications`, dan kegagalan kirim tidak boleh membatalkan catatan
        // pelanggaran yang sudah sah.
        $this->notifyViolation($violation, $type);
        $this->notifyThresholds($violation, $actions);

        return [$violation->refresh(), $actions];
    }

    /**
     * Sunting catatan pelanggaran. `points` TIDAK ikut berubah kecuali jenis
     * pelanggarannya diganti — snapshot historis tidak boleh dikoreksi diam-diam.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(BoardingViolation $violation, array $data, ?User $actor = null): BoardingViolation
    {
        $this->assertStudent($violation->student_id, $actor);

        $payload = [];

        if (array_key_exists('violation_type_id', $data) && (int) $data['violation_type_id'] !== (int) $violation->violation_type_id) {
            $type = $this->findType($data['violation_type_id']);
            $payload['violation_type_id'] = $type->id;
            $payload['points'] = (int) $type->point;
        }

        foreach (['narrative' => 'narrative', 'evidence_path' => 'evidence_path'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $payload[$column] = $this->text($data[$key]);
            }
        }

        if (array_key_exists('date', $data)) {
            $payload['date'] = $data['date'];
        }

        if (array_key_exists('status', $data)) {
            $this->assertStatus((string) $data['status']);
            $payload['status'] = $data['status'];
        }

        if ($payload === []) {
            return $violation;
        }

        DB::transaction(fn (): bool => (bool) $violation->update($payload));

        return $violation->refresh();
    }

    /**
     * Hapus catatan yang salah input.
     *
     * Riwayat pelanggaran memang boleh dicabut (salah santri, salah jenis), dan
     * `boarding_disciplinary_actions.violation_id` nullable dengan `nullOnDelete`
     * justru dirancang untuk ini: tindakannya tetap ada, hanya rujukannya lepas.
     * Penghapusan paksa dilakukan eksplisit di sini supaya perilakunya sama pada
     * SQLite (FK bisa mati) maupun MySQL.
     */
    public function delete(BoardingViolation $violation, ?User $actor = null): void
    {
        $this->assertStudent($violation->student_id, $actor);

        DB::transaction(function () use ($violation): void {
            BoardingDisciplinaryAction::query()
                ->where('violation_id', $violation->id)
                ->update(['violation_id' => null]);

            $violation->delete();
        });
    }

    /**
     * Total poin sepanjang waktu untuk satu santri (bukan per periode — §37).
     */
    public function totalPointsFor(int $studentId): int
    {
        return (int) BoardingViolation::query()->where('student_id', $studentId)->sum('points');
    }

    /**
     * Muatan penuh endpoint `GET boarding/students/{student}/points`:
     * total, rincian per kategori, status ambang, dan catatan terbaru.
     *
     * @return array<string, mixed>
     */
    public function pointsSummary(Student|int $student, ?User $actor = null, int $recentLimit = self::RECENT_LIMIT): array
    {
        $studentId = $student instanceof Student ? (int) $student->id : (int) $student;

        $this->assertStudent($studentId, $actor);

        $total = $this->totalPointsFor($studentId);
        $open = BoardingViolation::query()->where('student_id', $studentId)->open()->count();

        return [
            'student_id' => $studentId,
            'point_label' => $this->pointLabel(),
            'total_points' => $total,
            'violation_count' => BoardingViolation::query()->where('student_id', $studentId)->count(),
            'open_count' => (int) $open,
            'by_category' => $this->breakdownByCategory($studentId),
            'thresholds' => $this->thresholdStatus($total),
            'next_threshold' => $this->nextThreshold($total),
            'recent' => BoardingViolation::query()
                ->with(['type:id,code,name,category,point,severity'])
                ->where('student_id', $studentId)
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->limit(max(0, $recentLimit))
                ->get()
                ->map(fn (BoardingViolation $v): array => [
                    'id' => (int) $v->id,
                    'date' => $v->date?->toDateString(),
                    'violation_type_id' => $v->violation_type_id === null ? null : (int) $v->violation_type_id,
                    'type_code' => $v->type?->code,
                    'type_name' => $v->type?->name,
                    'category' => $v->type?->category,
                    'category_label' => $v->type?->categoryLabel(),
                    'severity' => $v->type?->severity,
                    'points' => (int) $v->points,
                    'status' => $v->status,
                    'status_label' => $v->statusLabel(),
                    'narrative' => $v->narrative,
                ])
                ->all(),
        ];
    }

    /**
     * Rincian poin per kategori master, satu query aggregate (bukan per baris).
     * `share` adalah persen kontribusi kategori terhadap total poin santri.
     *
     * @return list<array<string, mixed>>
     */
    public function breakdownByCategory(int $studentId): array
    {
        $rows = BoardingViolation::query()
            ->join('boarding_violation_types', 'boarding_violation_types.id', '=', 'boarding_violations.violation_type_id')
            ->where('boarding_violations.student_id', $studentId)
            ->groupBy('boarding_violation_types.category')
            ->selectRaw('boarding_violation_types.category as category, COUNT(*) as violation_count, COALESCE(SUM(boarding_violations.points), 0) as points')
            ->orderByDesc('points')
            ->get();

        $sum = 0;

        foreach ($rows as $row) {
            $sum += (int) $row->points;
        }

        $breakdown = [];

        foreach ($rows as $row) {
            $category = (string) $row->category;
            $points = (int) $row->points;

            $breakdown[] = [
                'category' => $category,
                'category_label' => BoardingViolationType::CATEGORY_LABELS[$category] ?? $category,
                'points' => $points,
                'violation_count' => (int) $row->violation_count,
                'share' => $sum > 0 ? round($points / $sum * 100, 1) : 0.0,
            ];
        }

        return $breakdown;
    }

    /**
     * Nilai ambang + apakah sudah terlewat pada `total`.
     *
     * @return list<array<string, mixed>>
     */
    public function thresholdStatus(?int $total = null): array
    {
        $total ??= 0;
        $settings = $this->settings->all();

        $thresholds = [];

        foreach (array_keys(self::THRESHOLD_ACTIONS) as $key) {
            $threshold = (int) ($settings[$key] ?? 0);
            $action = self::THRESHOLD_ACTIONS[$key];

            $thresholds[] = [
                'key' => $key,
                'action' => $action,
                'action_label' => BoardingDisciplinaryAction::ACTION_LABELS[$action] ?? $action,
                'threshold' => $threshold,
                'reached' => $total >= $threshold,
                'remaining' => max(0, $threshold - $total),
            ];
        }

        usort($thresholds, fn (array $a, array $b): int => $a['threshold'] <=> $b['threshold']);

        return $thresholds;
    }

    /**
     * Ambang terdekat yang BELUM terlewat, atau null bila semua sudah lewat.
     *
     * @return array<string, mixed>|null
     */
    public function nextThreshold(int $total): ?array
    {
        foreach ($this->thresholdStatus($total) as $threshold) {
            if (! $threshold['reached']) {
                return $threshold;
            }
        }

        return null;
    }

    /**
     * Buat tindakan pendisiplinan otomatis untuk setiap ambang yang baru saja
     * dilewati. Dipanggil dari dalam transaksi `record()`.
     *
     * @return list<BoardingDisciplinaryAction>
     */
    protected function applyThresholds(int $studentId, int $before, ?User $actor): array
    {
        $after = $this->totalPointsFor($studentId);
        $created = [];

        foreach ($this->thresholdStatus($after) as $threshold) {
            $limit = (int) $threshold['threshold'];

            // Hanya PENAMPAKAN yang dihitung (§37).
            if ($before >= $limit || $after < $limit) {
                continue;
            }

            $notes = $this->autoNotes($limit);

            // Idempotensi: satu ambang = satu tindakan otomatis per santri.
            if (BoardingDisciplinaryAction::query()
                ->where('student_id', $studentId)
                ->where('action', $threshold['action'])
                ->where('notes', $notes)
                ->exists()) {
                continue;
            }

            $created[] = BoardingDisciplinaryAction::create([
                'student_id' => $studentId,
                // Tanpa `violation_id`: tindakan ini lahir dari akumulasi poin,
                // bukan satu kejadian tunggal (§37).
                'violation_id' => null,
                'action' => $threshold['action'],
                'date' => now()->toDateString(),
                'officer' => $actor?->name,
                'status' => BoardingDisciplinaryAction::STATUS_PENDING,
                'notes' => $notes,
                'recorded_by' => $actor?->id,
            ]);
        }

        return $created;
    }

    /**
     * Kalimat otomatis yang sama dipakai sebagai kunci idempotensi, jadi ia
     * harus deterministik — jangan tambahkan tanggal di dalamnya.
     */
    public function autoNotes(int $threshold): string
    {
        return 'Otomatis: total poin melewati ambang '.$threshold;
    }

    /**
     * Kabar orang tua atas satu catatan pelanggaran baru.
     */
    protected function notifyViolation(BoardingViolation $violation, ?BoardingViolationType $type = null): int
    {
        $type ??= $violation->type;
        $label = $this->pointLabel();
        $student = $violation->student ?? Student::query()->find($violation->student_id);

        $title = sprintf(
            '%s tercatat: %s (+%d %s)',
            'Pelanggaran asrama',
            $type?->name ?? 'tanpa jenis',
            (int) $violation->points,
            $label,
        );

        $body = sprintf(
            'Ananda %s tercatat melanggar "%s" pada %s. Total %s saat ini %d. %s',
            $student?->full_name ?? 'santri',
            $type?->name ?? '-',
            $violation->date?->format('d/m/Y') ?? '-',
            $label,
            $this->totalPointsFor((int) $violation->student_id),
            filled($violation->narrative) ? 'Keterangan: '.$violation->narrative : 'Tanpa keterangan tambahan.',
        );

        return $this->notifications->toParentsOf(
            (int) $violation->student_id,
            'violation_recorded',
            $title,
            $body,
            [
                'reference_type' => BoardingViolation::class,
                'reference_id' => (int) $violation->id,
                'status' => $violation->status,
                'points' => (int) $violation->points,
                'route' => '/boarding/portal/children/'.$violation->student_id.'/violations',
            ],
        );
    }

    /**
     * Satu kabar per ambang yang baru dilewati: orang tua santri + antrean kerja
     * petugas kedisiplinan. `alreadySent()` menjadi penjaga terakhir, jadi
     * pemanggilan berulang pada tindakan yang sama tidak menumpuk pesan.
     *
     * Kuncinya adalah `reference_type`/`reference_id` = baris tindakan
     * pendisiplinan itu sendiri. Kunci itu unik per (santri, ambang) karena
     * setiap penampakan ambang menghasilkan satu baris tindakan baru.
     *
     * @param  list<BoardingDisciplinaryAction>  $actions
     */
    protected function notifyThresholds(BoardingViolation $violation, array $actions): void
    {
        if ($actions === []) {
            return;
        }

        $label = $this->pointLabel();
        $studentId = (int) $violation->student_id;
        $total = $this->totalPointsFor($studentId);
        $thresholds = $this->thresholdStatus($total);

        foreach ($actions as $action) {
            $threshold = null;

            foreach ($thresholds as $candidate) {
                if ($candidate['action'] === $action->action && $candidate['reached']) {
                    $threshold = $candidate;

                    break;
                }
            }

            $limit = $threshold['threshold'] ?? 0;

            $title = sprintf('Total %s ananda mencapai %d (ambang %d)', $label, $total, $limit);
            $body = sprintf(
                'Total %s ananda sekarang %d, melewati ambang %d. Tindakan otomatis "%s" dibuat dan menunggu ditindaklanjuti petugas asrama.',
                $label,
                $total,
                $limit,
                $action->actionLabel(),
            );

            $context = [
                'reference_type' => BoardingDisciplinaryAction::class,
                'reference_id' => (int) $action->id,
                'status' => $action->status,
                'total_points' => $total,
                'threshold' => $limit,
                'route' => '/boarding/portal/children/'.$studentId.'/violations',
            ];

            $this->notifications->toParentsOf($studentId, 'point_threshold_reached', $title, $body, $context);
            $this->notifications->toStaff(self::STAFF_PERMISSION, 'point_threshold_reached', $title, $body, $context);
        }
    }

    // =====================================================================
    // TINDAKAN PENDISIPLINAN (§37, §38)
    // =====================================================================

    /**
     * @param  array<string, mixed>  $filters
     */
    public function actionQuery(array $filters = [], ?User $actor = null): Builder
    {
        $allowed = $this->scope->studentIdsFor($actor);

        return BoardingDisciplinaryAction::query()
            ->with(['student:id,full_name,nis', 'violation:id,violation_type_id,date,points', 'recordedBy:id,name'])
            ->forStudent(is_numeric($filters['student_id'] ?? null) ? (int) $filters['student_id'] : null)
            ->between($filters['from'] ?? null, $filters['to'] ?? null)
            ->when(filled($filters['action'] ?? null), fn (Builder $q) => $q->where('action', $filters['action']))
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->whereIn('status', (array) $filters['status']))
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('student_id', $allowed ?: [0]))
            ->orderByDesc('date')
            ->orderByDesc('id');
    }

    /**
     * Tindakan manual (§38). Bedanya dengan yang otomatis: petugas memilih sendiri
     * jenisnya dan boleh menunjuk satu catatan pelanggaran sebagai pemicu.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordAction(array $data, ?User $actor = null): BoardingDisciplinaryAction
    {
        $student = $this->assertStudent($data['student_id'] ?? null, $actor);
        $this->assertActionKind((string) ($data['action'] ?? ''));

        $violation = null;

        if (filled($data['violation_id'] ?? null)) {
            $violation = BoardingViolation::query()->find($data['violation_id']);

            if ($violation === null) {
                throw ValidationException::withMessages(['violation_id' => 'Catatan pelanggaran tidak ditemukan.']);
            }

            if ((int) $violation->student_id !== (int) $student->id) {
                throw ValidationException::withMessages([
                    'violation_id' => 'Catatan pelanggaran tersebut bukan milik santri ini.',
                ]);
            }
        }

        return DB::transaction(function () use ($data, $student, $violation, $actor): BoardingDisciplinaryAction {
            $action = BoardingDisciplinaryAction::create([
                'student_id' => $student->id,
                'violation_id' => $violation?->id,
                'action' => $data['action'],
                'date' => $data['date'] ?? now()->toDateString(),
                'officer' => $this->text($data['officer'] ?? null) ?? $actor?->name,
                'status' => array_key_exists('status', $data) ? $data['status'] : BoardingDisciplinaryAction::STATUS_PENDING,
                'notes' => $this->text($data['notes'] ?? null),
                'recorded_by' => $actor?->id,
            ]);

            // violations.status `open` → `actioned` (§36): penanda bagi petugas
            // bahwa kasus itu sudah punya tindak lanjut, bukan keputusan final.
            if ($violation !== null && $violation->isOpen()) {
                $violation->update(['status' => BoardingViolation::STATUS_ACTIONED]);
            }

            return $action;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAction(BoardingDisciplinaryAction $action, array $data, ?User $actor = null): BoardingDisciplinaryAction
    {
        $this->assertStudent($action->student_id, $actor);

        $payload = [];

        if (array_key_exists('action', $data)) {
            $this->assertActionKind((string) $data['action']);
            $payload['action'] = $data['action'];
        }

        if (array_key_exists('status', $data)) {
            $this->assertActionStatus((string) $data['status']);
            $payload['status'] = $data['status'];
        }

        foreach (['officer', 'notes'] as $key) {
            if (array_key_exists($key, $data)) {
                $payload[$key] = $this->text($data[$key]);
            }
        }

        if (array_key_exists('date', $data)) {
            $payload['date'] = $data['date'];
        }

        if ($payload === []) {
            return $action;
        }

        DB::transaction(fn (): bool => (bool) $action->update($payload));

        return $action->refresh();
    }

    /**
     * Putus antrean kerja: pending → completed/cancelled (§38).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateActionStatus(BoardingDisciplinaryAction $action, string $status, ?User $actor = null, array $data = []): BoardingDisciplinaryAction
    {
        $this->assertActionStatus($status);

        if ($status === BoardingDisciplinaryAction::STATUS_COMPLETED && blank($data['notes'] ?? null) && blank($action->getRawOriginal('notes'))) {
            throw ValidationException::withMessages([
                'notes' => 'Cantumkan hasil tindakannya sebelum menandai selesai.',
            ]);
        }

        return $this->updateAction($action, array_filter([
            'status' => $status,
            'notes' => $data['notes'] ?? null,
            'officer' => $data['officer'] ?? null,
            'date' => $data['date'] ?? null,
        ], static fn (mixed $value): bool => $value !== null), $actor);
    }

    public function deleteAction(BoardingDisciplinaryAction $action, ?User $actor = null): void
    {
        $this->assertStudent($action->student_id, $actor);

        $action->delete();
    }

    // =====================================================================
    // PEMBANTU
    // =====================================================================

    /** Istilah poin yang dipakai seragam di judul notifikasi & laporan (§37). */
    public function pointLabel(): string
    {
        return (string) ($this->settings->get('point_label') ?? 'Poin Pelanggaran');
    }

    /**
     * Nama santri dibutuhkan teks notifikasi; muat sekali di luar transaksi.
     */
    protected function loadMissingForNotification(BoardingViolation $violation): void
    {
        if (! $violation->relationLoaded('student')) {
            $violation->load('student:id,full_name,nis');
        }

        if (! $violation->relationLoaded('type')) {
            $violation->load('type:id,code,name,category,point,severity');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function typePayload(array $data): array
    {
        $payload = [];

        foreach (['code', 'name', 'description'] as $key) {
            if (array_key_exists($key, $data)) {
                $payload[$key] = $this->text($data[$key]);
            }
        }

        if (array_key_exists('category', $data)) {
            $this->assertCategory((string) $data['category']);
            $payload['category'] = $data['category'];
        }

        if (array_key_exists('severity', $data)) {
            $this->assertSeverity((string) $data['severity']);
            $payload['severity'] = $data['severity'];
        }

        if (array_key_exists('point', $data)) {
            $payload['point'] = max(0, (int) $data['point']);
        }

        if (array_key_exists('is_active', $data)) {
            $payload['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        return $payload;
    }

    /**
     * @return Student  santri yang diketahui dan boleh diakses $actor
     */
    public function assertStudent(mixed $studentId, ?User $actor): Student
    {
        $id = is_numeric($studentId) ? (int) $studentId : 0;

        $student = $id > 0 ? Student::query()->find($id) : null;

        if ($student === null) {
            throw ValidationException::withMessages([
                'student_id' => 'Santri tidak ditemukan.',
            ]);
        }

        $this->scope->assertCanAccessStudent($actor, (int) $student->id);

        return $student;
    }

    /**
     * Filter `student_id` pada daftar: `null` = tidak difilter, tetapi nilai
     * yang dikirim klien tetap harus berada di dalam hak aksesnya (ditegakkan
     * `query()` lewat `whereIn`), sehingga filter tidak menjadi jalan belakang.
     */
    protected function studentFilter(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected function assertCodeAvailable(string $code, mixed $exceptId = null): void
    {
        if ($code === '') {
            return;
        }

        $exists = BoardingViolationType::query()
            ->where('code', $code)
            ->when($exceptId !== null, fn (Builder $q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => "Kode jenis pelanggaran \"{$code}\" sudah dipakai.",
            ]);
        }
    }

    protected function assertCategory(string $category): void
    {
        if (! in_array($category, BoardingViolationType::CATEGORIES, true)) {
            throw ValidationException::withMessages([
                'category' => 'Kategori pelanggaran harus salah satu dari: '.implode(', ', BoardingViolationType::CATEGORIES).'.',
            ]);
        }
    }

    protected function assertSeverity(string $severity): void
    {
        if (! in_array($severity, BoardingViolationType::SEVERITIES, true)) {
            throw ValidationException::withMessages([
                'severity' => 'Tingkat keparahan harus salah satu dari: '.implode(', ', BoardingViolationType::SEVERITIES).'.',
            ]);
        }
    }

    protected function assertStatus(string $status): void
    {
        if (! in_array($status, BoardingViolation::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Status pelanggaran harus salah satu dari: '.implode(', ', BoardingViolation::STATUSES).'.',
            ]);
        }
    }

    protected function assertActionKind(string $action): void
    {
        if (! in_array($action, BoardingDisciplinaryAction::ACTIONS, true)) {
            throw ValidationException::withMessages([
                'action' => 'Jenis tindakan harus salah satu dari: '.implode(', ', BoardingDisciplinaryAction::ACTIONS).'.',
            ]);
        }
    }

    protected function assertActionStatus(string $status): void
    {
        if (! in_array($status, BoardingDisciplinaryAction::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Status tindakan harus salah satu dari: '.implode(', ', BoardingDisciplinaryAction::STATUSES).'.',
            ]);
        }
    }

    protected function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }
}
