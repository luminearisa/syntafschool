<?php

namespace App\Services\Boarding;

use App\Models\BoardingHomecoming;
use App\Models\BoardingPlacement;
use App\Models\Student;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Kepulangan santri ke rumah (§30, §31) — alur sama seperti izin keluar, tetapi
 * dimensinya hari bukan jam, dan persetujuan cukup satu langkah.
 *
 * ## Kenapa dipisah dari `BoardingPermissionService`
 *
 * Tabelnya berbeda (`boarding_homecomings`, §30): kepulangan dicatat dengan
 * `home_date`/`return_date` dan siapa yang menjemput/mengantar, karena akhir
 * pekan dan libur semester terjadi serentak untuk seluruh isi pondok. Mencampur
 * keduanya ke satu layanan berarti jam kembali wajib sementara kepulangan tidak
 * punya jam, dan antrean gerbang tidak bisa dibedakan.
 *
 * ## Keputusan yang menopang sisanya
 *
 * 1. **Persetujuan satu langkah** (`boarding.homecoming.approve`) dengan cap
 *    `approved_by`/`approved_at` pada barisnya — tidak ada `current_step` di
 *    tabel ini, jadi langkah kedua tidak pernah dijanjikan ke petugas.
 * 2. **Satu santri tidak bisa berada di dua keadaan sekaligus.** Sebelum santri
 *    dicatat pulang, izin keluar miliknya yang masih `out` ikut diperiksa lewat
 *    `BoardingPermissionService::assertStudentNotOutElsewhere()` — jalur gerbang
 *    yang sama dipakai dua modul (§42).
 * 3. **Terlambat dihitung antar tanggal**, bukan jam (§31): `actual_return_date`
 *    lewat `return_date` berarti santri belum kembali pada hari ia seharusnya
 *    masuk, dan itu pemberitahuan ke orang tua.
 * 4. Semua tulis di dalam `DB::transaction` dengan baris dikunci, penjaga bisnis
 *    lewat `ValidationException::withMessages` (422 berkoncal).
 */
class BoardingHomecomingService
{
    public const NOTIFY_SUBMITTED = 'homecoming_submitted';

    public const NOTIFY_APPROVED = 'homecoming_approved';

    public const NOTIFY_REJECTED = 'homecoming_rejected';

    public const NOTIFY_CHECKED_OUT = 'homecoming_checked_out';

    public const NOTIFY_LATE_RETURN = 'homecoming_late_return';

    public const NOTIFY_CANCELLED = 'homecoming_cancelled';

    public const NOTIFY_EXPIRED = 'homecoming_expired';

    public const APPROVE_ABILITY = 'boarding.homecoming.approve';

    public function __construct(
        private readonly BoardingNotificationService $notifications,
        private readonly BoardingScopeService $scopes,
        private readonly BoardingPermissionService $permissions,
    ) {
    }

    // =====================================================================
    // Baca
    // =====================================================================

    /**
     * Daftar kepulangan (§30): filter status, jenis, santri, asrama, rentang
     * tanggal, plus `overdue=1` untuk "seharusnya sudah kembali" (§31).
     */
    public function query(Request $request, ?User $actor = null): Builder
    {
        $query = BoardingHomecoming::query()
            ->with(['student:id,full_name,nis,nisn'])
            ->orderByDesc('home_date')
            ->orderByDesc('id');

        $allowedIds = $this->scopes->studentIdsFor($actor);

        if ($allowedIds !== null) {
            $query->whereIn('student_id', $allowedIds);
        }

        return $query
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->ofType($request->filled('type') ? $request->string('type')->toString() : null)
            ->forStudent($request->filled('student_id') ? (int) $request->input('student_id') : null)
            ->when($request->filled('dormitory_id'), fn (Builder $q) => $q->whereIn(
                'student_id',
                BoardingPlacement::query()
                    ->active()
                    ->where('dormitory_id', (int) $request->input('dormitory_id'))
                    ->pluck('student_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all(),
            ))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('home_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('home_date', '<=', $request->date('to')))
            ->when($request->boolean('overdue'), fn (Builder $q) => $q->overdue())
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));

                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('picked_up_by', 'like', "%$term%")
                        ->orWhere('transport', 'like', "%$term%")
                        ->orWhereHas('student', fn (Builder $s) => $s
                            ->where('full_name', 'like', "%$term%")
                            ->orWhere('nis', 'like', "%$term%"));
                });
            });
    }

    public function withDetails(BoardingHomecoming $homecoming): BoardingHomecoming
    {
        return $homecoming->load([
            'student:id,full_name,nis,nisn',
            'requestedBy:id,name',
            'approvedBy:id,name',
            'checkedOutBy:id,name',
            'checkedInBy:id,name',
        ]);
    }

    public function assertAccessible(BoardingHomecoming $homecoming, ?User $actor): void
    {
        if ($actor === null) {
            return;
        }

        $this->scopes->assertCanAccessStudent($actor, (int) $homecoming->student_id);
    }

    // =====================================================================
    // Buat & ajukan
    // =====================================================================

    /**
     * Buat kepulangan (§30). `submit = true` langsung memasukkannya ke antrean
     * persetujuan; tanpa itu tersimpan sebagai `draft`.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null, bool $submit = false): BoardingHomecoming
    {
        $student = $this->assertStudentPlaced($data['student_id'] ?? null, $actor);

        $homeDate = $this->date($data['home_date'] ?? null, 'home_date');
        $returnDate = $this->date($data['return_date'] ?? null, 'return_date');

        if ($returnDate->lessThan($homeDate)) {
            throw ValidationException::withMessages([
                'return_date' => 'Tanggal kembali tidak boleh mendahului tanggal pulang (§30).',
            ]);
        }

        $this->assertNoOverlappingOpenWindow((int) $student->id, $homeDate, $returnDate);

        $status = $submit ? BoardingHomecoming::STATUS_SUBMITTED : BoardingHomecoming::STATUS_DRAFT;

        /** @var BoardingHomecoming $homecoming */
        $homecoming = DB::transaction(function () use ($data, $student, $actor, $homeDate, $returnDate, $status): BoardingHomecoming {
            $homecoming = BoardingHomecoming::create([
                'student_id' => $student->id,
                'type' => $data['type'] ?? BoardingHomecoming::TYPE_WEEKEND,
                'home_date' => $homeDate,
                'return_date' => $returnDate,
                'transport' => $this->nullableText($data['transport'] ?? null, 100),
                'picked_up_by' => $this->nullableText($data['picked_up_by'] ?? null, 100),
                'returned_with' => $this->nullableText($data['returned_with'] ?? null, 100),
                'status' => $status,
                'requested_by' => $actor?->id,
                'notes' => $this->nullableText($data['notes'] ?? null, 1000),
            ]);

            $this->audit($homecoming, 'BOARDING_HOMECOMING_CREATED', 'Kepulangan dibuat', [
                'student_id' => (int) $homecoming->student_id,
            ]);

            return $homecoming;
        });

        if ($submit) {
            $this->notifyApprovalQueue($homecoming);
        }

        return $this->withDetails($homecoming);
    }

    /** `draft` → `submitted` (§30). */
    public function submit(BoardingHomecoming $homecoming, ?User $actor = null): BoardingHomecoming
    {
        $this->assertAccessible($homecoming, $actor);

        $from = (string) $homecoming->status;

        /** @var BoardingHomecoming $submitted */
        $submitted = DB::transaction(function () use ($homecoming, $from): BoardingHomecoming {
            $locked = $this->lock((int) $homecoming->id);

            if ($locked->status !== BoardingHomecoming::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya kepulangan berstatus draft yang dapat diajukan (status saat ini: '
                        .$this->statusNow($locked).') (§30).',
                ]);
            }

            $locked->forceFill(['status' => BoardingHomecoming::STATUS_SUBMITTED])->save();

            $this->audit($locked, 'BOARDING_HOMECOMING_SUBMITTED', 'Kepulangan diajukan', [
                'from' => $from,
                'to' => (string) $locked->status,
            ]);

            return $locked;
        });

        $this->notifyApprovalQueue($submitted);

        return $this->withDetails($submitted);
    }

    // =====================================================================
    // Persetujuan (§30)
    // =====================================================================

    /**
     * Setujui kepulangan — satu langkah, langsung `approved` (§30).
     *
     * Scope pengasuh ditegakkan di samping middleware rute: hanya santri binaan
     * yang boleh diputus kepulangannya (§54).
     */
    public function approve(BoardingHomecoming $homecoming, ?User $actor = null, ?string $note = null): BoardingHomecoming
    {
        $this->assertAccessible($homecoming, $actor);

        /** @var BoardingHomecoming $approved */
        $approved = DB::transaction(function () use ($homecoming, $actor, $note): BoardingHomecoming {
            $locked = $this->lock((int) $homecoming->id);

            if ($locked->status !== BoardingHomecoming::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya kepulangan berstatus menunggu persetujuan yang dapat disetujui (status saat ini: '
                        .$this->statusNow($locked).') (§30).',
                ]);
            }

            $locked->forceFill([
                'status' => BoardingHomecoming::STATUS_APPROVED,
                'approved_by' => $actor?->id,
                'approved_at' => Carbon::now(),
                'notes' => $this->appendNote((string) $locked->notes, $note),
            ])->save();

            $this->audit($locked, 'BOARDING_HOMECOMING_APPROVED', 'Kepulangan disetujui', [
                'approved_by' => $actor?->id,
            ]);

            return $locked;
        });

        $this->notifications->toParentsOf(
            (int) $approved->student_id,
            self::NOTIFY_APPROVED,
            'Kepulangan disetujui',
            $this->studentLabel($approved).' '.$this->typeLabel($approved).' disetujui. '
                .'Pulang '.($this->dateLabel($approved->home_date) ?? '-').' dan seharusnya kembali '
                .($this->dateLabel($approved->return_date) ?? '-').'.',
            $this->context($approved),
        );

        return $this->withDetails($approved);
    }

    /**
     * Tolak kepulangan pada tahap mana pun sebelum santri berangkat (§30).
     */
    public function reject(BoardingHomecoming $homecoming, ?User $actor = null, ?string $note = null): BoardingHomecoming
    {
        $this->assertAccessible($homecoming, $actor);

        $note = trim((string) $note);

        if (mb_strlen($note) < 5) {
            throw ValidationException::withMessages([
                'note' => 'Alasan penolakan wajib diisi (minimal 5 karakter) agar orang tua tahu apa yang perlu disesuaikan.',
            ]);
        }

        /** @var BoardingHomecoming $rejected */
        $rejected = DB::transaction(function () use ($homecoming, $actor, $note): BoardingHomecoming {
            $locked = $this->lock((int) $homecoming->id);

            if (! in_array($locked->status, [
                BoardingHomecoming::STATUS_DRAFT,
                BoardingHomecoming::STATUS_SUBMITTED,
                BoardingHomecoming::STATUS_APPROVED,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Kepulangan dengan status '.$this->statusNow($locked)
                        .' sudah final dan tidak dapat ditolak lagi (§30).',
                ]);
            }

            $locked->forceFill([
                'status' => BoardingHomecoming::STATUS_REJECTED,
                'approved_by' => $actor?->id,
                'approved_at' => Carbon::now(),
                'notes' => $this->appendNote((string) $locked->notes, 'Ditolak: '.$note),
            ])->save();

            $this->audit($locked, 'BOARDING_HOMECOMING_REJECTED', 'Kepulangan ditolak', [
                'note' => Str::limit($note, 200, ''),
            ]);

            return $locked;
        });

        $this->notifications->toParentsOf(
            (int) $rejected->student_id,
            self::NOTIFY_REJECTED,
            'Kepulangan ditolak',
            $this->studentLabel($rejected).' '.$this->typeLabel($rejected).' ditolak. Alasan: '.$note,
            $this->context($rejected),
        );

        return $this->withDetails($rejected);
    }

    // =====================================================================
    // Gerbang (§30, §31)
    // =====================================================================

    /**
     * `approved` → `out`: santri dijemput dan tercatat keluar asrama (§30).
     */
    public function checkOut(BoardingHomecoming $homecoming, ?User $actor = null, mixed $at = null): BoardingHomecoming
    {
        $this->assertAccessible($homecoming, $actor);

        $moment = $at === null ? Carbon::now() : $this->moment($at, 'checked_out_at');

        /** @var BoardingHomecoming $out */
        $out = DB::transaction(function () use ($homecoming, $actor, $moment): BoardingHomecoming {
            $locked = $this->lock((int) $homecoming->id);

            if ($locked->status !== BoardingHomecoming::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'status' => 'Santri hanya dapat dijemput atas kepulangan yang sudah disetujui (status saat ini: '
                        .$this->statusNow($locked).') (§30).',
                ]);
            }

            if (! $this->hasActivePlacement((int) $locked->student_id)) {
                throw ValidationException::withMessages([
                    'student' => 'Santri ini tidak memiliki penempatan aktif di asrama, sehingga tidak ada yang dapat dipulangkan (§30).',
                ]);
            }

            // Izin keluar lain yang masih `out` memblokir: satu santri satu keadaan (§42).
            $this->permissions->assertStudentNotOutElsewhere((int) $locked->student_id, null, (int) $locked->id);

            $locked->forceFill([
                'status' => BoardingHomecoming::STATUS_OUT,
                'checked_out_by' => $actor?->id,
            ])->save();

            $this->audit($locked, 'BOARDING_HOMECOMING_CHECKED_OUT', 'Santri di-check out untuk kepulangan', [
                'checked_out_at' => $moment->toIso8601String(),
                'checked_out_by' => $actor?->id,
            ]);

            return $locked;
        });

        $this->notifications->toParentsOf(
            (int) $out->student_id,
            self::NOTIFY_CHECKED_OUT,
            'Santri pulang ke rumah',
            $this->studentLabel($out).' '.$this->typeLabel($out).' sudah dijemput'
                .(filled($out->picked_up_by) ? ' oleh '.$out->picked_up_by : '')
                .(filled($out->transport) ? ' ('.$out->transport.')' : '')
                .'. Seharusnya kembali '.($this->dateLabel($out->return_date) ?? '-').'.',
            $this->context($out),
        );

        return $this->withDetails($out);
    }

    /**
     * `out` → `returned` (§31). Terlambat bila tanggal kembali nyata lewat
     * `return_date` — tidak ada tenggat menit di sini, dimensinya hari.
     */
    public function checkIn(BoardingHomecoming $homecoming, ?User $actor = null, mixed $actual = null): BoardingHomecoming
    {
        $this->assertAccessible($homecoming, $actor);

        $actualDate = $actual === null ? Carbon::now() : $this->date($actual, 'actual_return_date');

        /** @var BoardingHomecoming $returned */
        $returned = DB::transaction(function () use ($homecoming, $actor, $actualDate): BoardingHomecoming {
            $locked = $this->lock((int) $homecoming->id);

            if ($locked->status !== BoardingHomecoming::STATUS_OUT) {
                throw ValidationException::withMessages([
                    'status' => 'Check in hanya dapat dicatat untuk kepulangan yang sedang berlangsung (status saat ini: '
                        .$this->statusNow($locked).') (§31).',
                ]);
            }

            $late = $locked->return_date !== null && $actualDate->greaterThan($locked->return_date);

            $locked->forceFill([
                'status' => BoardingHomecoming::STATUS_RETURNED,
                'actual_return_date' => $actualDate,
                'late_return' => $late,
                'checked_in_by' => $actor?->id,
            ])->save();

            $this->audit($locked, 'BOARDING_HOMECOMING_CHECKED_IN', $late
                ? 'Santri kembali terlambat dari kepulangan'
                : 'Santri kembali dari kepulangan', [
                'actual_return_date' => $actualDate->toDateString(),
                'late_return' => $late,
                'checked_in_by' => $actor?->id,
            ]);

            return $locked;
        });

        if ($returned->late_return) {
            $days = ($returned->return_date === null || $returned->actual_return_date === null)
                ? null
                : (int) round(($returned->actual_return_date->getTimestamp() - $returned->return_date->getTimestamp()) / 86400);

            $this->notifications->toParentsOf(
                (int) $returned->student_id,
                self::NOTIFY_LATE_RETURN,
                'Santri kembali terlambat dari kepulangan',
                $this->studentLabel($returned).' kembali '.($this->dateLabel($returned->actual_return_date) ?? '-')
                    .', terlambat '.($days !== null ? $days.' hari ' : '')
                    .'dari tanggal seharusnya ('.($this->dateLabel($returned->return_date) ?? '-').' , §31). '
                    .'Mohon konfirmasi ke pengasuh.',
                $this->context($returned),
            );
        }

        return $this->withDetails($returned);
    }

    /**
     * Batalkan kepulangan sebelum santri dijemput (§30). `out` tidak bisa dibatalkan
     * — jalannya tutup hanya lewat check in.
     */
    public function cancel(BoardingHomecoming $homecoming, ?User $actor = null, ?string $note = null): BoardingHomecoming
    {
        $this->assertAccessible($homecoming, $actor);

        /** @var BoardingHomecoming $cancelled */
        $cancelled = DB::transaction(function () use ($homecoming, $actor, $note): BoardingHomecoming {
            $locked = $this->lock((int) $homecoming->id);

            if (! in_array($locked->status, [
                BoardingHomecoming::STATUS_DRAFT,
                BoardingHomecoming::STATUS_SUBMITTED,
                BoardingHomecoming::STATUS_APPROVED,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Kepulangan yang sedang '.$this->statusNow($locked)
                        .' tidak dapat dibatalkan. Tandai kepulangan santri di gerbang (§31).',
                ]);
            }

            $locked->forceFill([
                'status' => BoardingHomecoming::STATUS_CANCELLED,
                'notes' => $this->appendNote((string) $locked->notes, $note),
            ])->save();

            $this->audit($locked, 'BOARDING_HOMECOMING_CANCELLED', 'Kepulangan dibatalkan', [
                'cancelled_by' => $actor?->id,
            ]);

            return $locked;
        });

        $this->notifications->toParentsOf(
            (int) $cancelled->student_id,
            self::NOTIFY_CANCELLED,
            'Kepulangan dibatalkan',
            $this->studentLabel($cancelled).' '.$this->typeLabel($cancelled).' dibatalkan'
                .(filled($note) ? ': '.trim((string) $note) : '.'),
            $this->context($cancelled),
        );

        return $this->withDetails($cancelled);
    }

    /**
     * Kedaluwarsakan kepulangan yang lewat tanggal kembali tanpa pernah
     * di-check out/in (§31) — dipakai perintah `boarding:expire-stale`.
     *
     * Santri yang sedang `out` tidak disentuh: kepulangannya ditutup manual oleh
     * petugas gerbang, dan status `expired` akan mengaburkan laporan §31.
     */
    public function expireStale(): int
    {
        $candidates = BoardingHomecoming::query()
            ->whereIn('status', [BoardingHomecoming::STATUS_SUBMITTED, BoardingHomecoming::STATUS_APPROVED])
            ->where('return_date', '<', Carbon::now()->toDateString())
            ->orderBy('id')
            ->pluck('id');

        $expired = 0;

        foreach ($candidates as $candidateId) {
            /** @var BoardingHomecoming|null $homecoming */
            $homecoming = DB::transaction(function () use ($candidateId): ?BoardingHomecoming {
                $locked = BoardingHomecoming::query()
                    ->whereKey($candidateId)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || ! in_array($locked->status, [
                    BoardingHomecoming::STATUS_SUBMITTED,
                    BoardingHomecoming::STATUS_APPROVED,
                ], true)) {
                    return null;
                }

                $locked->forceFill(['status' => BoardingHomecoming::STATUS_EXPIRED])->save();

                $this->audit($locked, 'BOARDING_HOMECOMING_EXPIRED', 'Kepulangan kedaluwarsa', [
                    'return_date' => $locked->return_date?->toDateString(),
                ]);

                return $locked;
            });

            if ($homecoming === null) {
                continue;
            }

            $expired++;

            $this->notifications->toParentsOf(
                (int) $homecoming->student_id,
                self::NOTIFY_EXPIRED,
                'Kepulangan kedaluwarsa',
                $this->studentLabel($homecoming).' '.$this->typeLabel($homecoming)
                    .' tidak terpakai sampai tanggal kembali ('
                    .($this->dateLabel($homecoming->return_date) ?? '-')
                    .') dan ditutup otomatis.',
                $this->context($homecoming),
            );
        }

        return $expired;
    }

    // =====================================================================
    // Penjaga keadaan
    // =====================================================================

    private function assertStudentPlaced(mixed $studentId, ?User $actor): Student
    {
        $id = (int) $studentId;

        if ($id <= 0) {
            throw ValidationException::withMessages(['student_id' => 'Santri wajib dipilih.']);
        }

        $student = Student::query()->whereKey($id)->first();

        if ($student === null) {
            throw ValidationException::withMessages(['student_id' => 'Santri tidak ditemukan.']);
        }

        if ($actor !== null) {
            $this->scopes->assertCanAccessStudent($actor, $id);
        }

        if (! $this->hasActivePlacement($id)) {
            throw ValidationException::withMessages([
                'student_id' => 'Santri ini tidak memiliki penempatan aktif di asrama, sehingga kepulangan tidak dapat dibuat (§30).',
            ]);
        }

        return $student;
    }

    private function hasActivePlacement(int $studentId): bool
    {
        return BoardingPlacement::query()
            ->where('student_id', $studentId)
            ->where('status', BoardingPlacement::STATUS_ACTIVE)
            ->exists();
    }

    /** Satu santri satu kepulangan terbuka pada rentang yang sama (§30). */
    private function assertNoOverlappingOpenWindow(int $studentId, Carbon $homeDate, Carbon $returnDate): void
    {
        $clash = BoardingHomecoming::query()
            ->where('student_id', $studentId)
            ->whereIn('status', BoardingHomecoming::OPEN_STATUSES)
            ->where('home_date', '<=', $returnDate->toDateString())
            ->where('return_date', '>=', $homeDate->toDateString())
            ->orderBy('home_date')
            ->first();

        if ($clash === null) {
            return;
        }

        throw ValidationException::withMessages([
            'home_date' => 'Santri ini sudah punya kepulangan '.$this->statusNow($clash).' pada rentang '
                .($this->dateLabel($clash->home_date) ?? '-').' sampai '.($this->dateLabel($clash->return_date) ?? '-')
                .'. Dua kepulangan tidak dapat berjalan bersamaan (§30).',
        ]);
    }

    private function lock(int $id): BoardingHomecoming
    {
        /** @var BoardingHomecoming $homecoming */
        $homecoming = BoardingHomecoming::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        return $homecoming;
    }

    private function statusNow(BoardingHomecoming $homecoming): string
    {
        return BoardingHomecoming::STATUS_LABELS[(string) $homecoming->status] ?? (string) $homecoming->status;
    }

    // =====================================================================
    // Notifikasi & audit
    // =====================================================================

    private function notifyApprovalQueue(BoardingHomecoming $homecoming): void
    {
        $this->notifications->toStaff(
            self::APPROVE_ABILITY,
            self::NOTIFY_SUBMITTED,
            'Kepulangan menunggu persetujuan',
            $this->studentLabel($homecoming).' '.$this->typeLabel($homecoming).' menunggu persetujuan ('
                .($this->dateLabel($homecoming->home_date) ?? '-').' sampai '
                .($this->dateLabel($homecoming->return_date) ?? '-').').',
            $this->context($homecoming),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function context(BoardingHomecoming $homecoming): array
    {
        return [
            'student_id' => (int) $homecoming->student_id,
            'reference_type' => BoardingHomecoming::class,
            'reference_id' => (int) $homecoming->id,
            'status' => (string) $homecoming->status,
            'homecoming_type' => (string) $homecoming->type,
        ];
    }

    private function studentLabel(BoardingHomecoming $homecoming): string
    {
        $homecoming->loadMissing('student:id,full_name');

        return (string) ($homecoming->student?->full_name ?? ('santri #'.$homecoming->student_id));
    }

    private function typeLabel(BoardingHomecoming $homecoming): string
    {
        return 'kepulangan '.strtolower((string) $homecoming->typeLabel());
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(BoardingHomecoming $homecoming, string $event, string $description, array $properties = []): void
    {
        activity('boarding')
            ->event($event)
            ->performedOn($homecoming)
            ->withProperties($properties + ['status' => (string) $homecoming->status])
            ->log($description);
    }

    // =====================================================================
    // Utilitas
    // =====================================================================

    /** Tanggal (tanpa jam) — `home_date`/`return_date`/`actual_return_date`. */
    public function date(mixed $value, string $field): Carbon
    {
        return $this->moment($value, $field)->startOfDay();
    }

    public function moment(mixed $value, string $field): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        $value = trim((string) $value);

        if ($value === '') {
            throw ValidationException::withMessages([$field => 'Tanggal wajib diisi.']);
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $field => 'Format tanggal "'.Str::limit($value, 40).' " tidak dikenali. Gunakan contoh: 2026-09-25.',
            ]);
        }
    }

    private function dateLabel(mixed $value): ?string
    {
        return $value instanceof Carbon ? $value->format('d/m/Y') : null;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $max, '');
    }

    private function appendNote(string $existing, ?string $extra): ?string
    {
        $extra = trim((string) $extra);

        if ($extra === '') {
            return $this->nullableText($existing, 1000);
        }

        $line = '['.Carbon::now()->format('d/m/Y H:i').'] '.$extra;

        return Str::limit($existing === '' ? $line : $existing.' | '.$line, 1000, '');
    }
}
