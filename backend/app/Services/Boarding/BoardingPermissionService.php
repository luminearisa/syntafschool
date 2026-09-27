<?php

namespace App\Services\Boarding;

use App\Models\BoardingHomecoming;
use App\Models\BoardingPermission;
use App\Models\BoardingPermissionApproval;
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
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Izin keluar santri (§27, §28, §29) — satu pintu untuk seluruh daur hidup:
 * buat → ajukan → (satu atau dua langkah) persetujuan → check out gerbang →
 * check in kembali, dengan jalur penutup `rejected`, `cancelled`, dan `expired`.
 *
 * ## Kenapa aturannya ada di sini, bukan di controller
 *
 * Layar yang sama dipakai wali kamar, kepala asrama, petugas gerbang, dan
 * menyusul portal orang tua. Kalau penjaga status ditulis di controller, salinan
 * berikutnya pasti berbeda sedikit — dan bedanya berupa santri yang keluar tanpa
 * izin sah. Kelas ini satu-satunya yang mengubah `boarding_permissions.status`.
 *
 * ## Keputusan yang menopang sisanya
 *
 * 1. **Jumlah langkah persetujuan datang dari konfigurasi modul**
 *    (`permission_approval_steps`, §27) dan dibekukan ke `required_steps` saat
 *    permintaan dibuat. Perubahan konfigurasi setelah itu tidak mengubah
 *    permintaan yang sedang berjalan — kalau tidak, langkah kedua bisa hilang di
 *    tengah antrean dan santri keluar dengan satu tanda tangan.
 * 2. **Dua jendela waktu tidak boleh tumpang tindih** untuk santri yang sama
 *    selagi masih terbuka (`submitted`/`approved`/`out`) — 422 (§28). Santri hanya
 *    bisa berada di satu tempat; izin ganda berarti laporan keberadaan (§42) bohong.
 * 3. **Check out menuntut santri benar-benar bisa keluar**: penempatan aktif ada,
 *    dan tidak ada izin/kepulangan lain yang masih `out`. Tabel kepulangan ikut
 *    diperiksa karena keduanya lewat gerbang yang sama.
 * 4. **Terlambat dihitung terhadap jam kembali + tenggat modul**
 *    (`permission_checkin_grace_minutes`, §29), bukan terhadap jam kembali saja;
 *    tanpa tenggat, antrean tulis tangan di gerbang selalu tercetak sebagai
 *    keterlambatan.
 * 5. **Setiap perpindahan status terjadi di dalam `DB::transaction`** dengan baris
 *    dikunci (`lockForUpdate`) dan dibaca ulang, sehingga dua petugas yang menekan
 *    tombol bersamaan tidak menghasilkan dua langkah untuk satu persetujuan.
 *
 * ## Catatan skema (§28)
 *
 * `boarding_permissions` menyimpan `checked_out_by`/`checked_in_by` tetapi tidak
 * punya kolom `checked_out_at`. Waktu check out karena itu ditulis pada properti
 * audit `BOARDING_PERMISSION_CHECKED_OUT` dan dibaca kembali saat halaman detail
 * dibuka (`withDetails()`), tanpa mengubah tabel milik modul fondasi.
 *
 * Notifikasi selalu lewat `BoardingNotificationService` (kanal + dedupe satu
 * bentuk); audit lewat `activity_log` berlog-name `boarding` dengan nama aksi
 * bergaya spesifikasi.
 */
class BoardingPermissionService
{
    /** Tipe notifikasi internal modul (dibaca frontend untuk ikon & tautan). */
    public const NOTIFY_SUBMITTED = 'permission_submitted';

    public const NOTIFY_APPROVED = 'permission_approved';

    public const NOTIFY_REJECTED = 'permission_rejected';

    public const NOTIFY_STUDENT_OUT = 'student_out';

    public const NOTIFY_LATE_RETURN = 'late_return';

    public const NOTIFY_CANCELLED = 'permission_cancelled';

    public const NOTIFY_EXPIRED = 'permission_expired';

    /** Antrean persetujuan — sasaran notifikasi pengajuan (§27). */
    public const APPROVE_ABILITY = 'boarding.permission.approve';

    /** Status yang sudah tertutup: tidak ada aksi lanjutan selain membaca. */
    public const TERMINAL_STATUSES = [
        BoardingPermission::STATUS_REJECTED,
        BoardingPermission::STATUS_RETURNED,
        BoardingPermission::STATUS_EXPIRED,
        BoardingPermission::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly BoardingSettingsService $settings,
        private readonly BoardingNotificationService $notifications,
        private readonly BoardingScopeService $scopes,
    ) {
    }

    // =====================================================================
    // Baca
    // =====================================================================

    /**
     * Daftar izin keluar (§28) dengan filter status, jenis, santri, asrama/kamar,
     * rentang tanggal, dan `overdue=1` untuk antrean "sudah lewat jam kembali".
     *
     * Daftar pengasuh dipotong `BoardingScopeService` lebih dulu: tanpa itu satu
     * endpoint `wali-kamar` membocorkan seluruh isi pondok (§54).
     */
    public function query(Request $request, ?User $actor = null): Builder
    {
        $query = BoardingPermission::query()
            ->with($this->eagerLoads())
            ->orderByDesc('start_at')
            ->orderByDesc('id');

        $allowedIds = $this->scopes->studentIdsFor($actor);

        if ($allowedIds !== null) {
            $query->whereIn('student_id', $allowedIds);
        }

        return $query
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->ofType($request->filled('permission_type') ? $request->string('permission_type')->toString() : null)
            ->forStudent($request->filled('student_id') ? (int) $request->input('student_id') : null)
            ->when($request->filled('dormitory_id'), fn (Builder $q) => $q->whereIn(
                'student_id',
                $this->studentIdsOnStructure('dormitory_id', (int) $request->input('dormitory_id')),
            ))
            ->when($request->filled('room_id'), fn (Builder $q) => $q->whereIn(
                'student_id',
                $this->studentIdsOnStructure('room_id', (int) $request->input('room_id')),
            ))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('start_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('start_at', '<=', $request->date('to')))
            ->when($request->boolean('overdue'), fn (Builder $q) => $q->lateReturners())
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));

                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('reason', 'like', "%$term%")
                        ->orWhere('destination', 'like', "%$term%")
                        ->orWhereHas('student', fn (Builder $s) => $s
                            ->where('full_name', 'like', "%$term%")
                            ->orWhere('nis', 'like', "%$term%"));
                });
            });
    }

    /**
     * Satu baris lengkap siap tampil: santri, jejak persetujuan, pelakunya, dan
     * waktu check out/in yang dibaca dari jejak audit.
     */
    public function withDetails(BoardingPermission $permission): BoardingPermission
    {
        $permission->load($this->detailLoads());

        $moments = $this->gateMomentsFor([(int) $permission->id]);

        $permission->setAttribute(
            'checked_out_at',
            $moments[(int) $permission->id]['checked_out_at'] ?? null,
        );
        $permission->setAttribute(
            'checked_in_at_record',
            $moments[(int) $permission->id]['checked_in_at'] ?? null,
        );

        return $permission;
    }

    /**
     * Gerbang akses satu baris — dipakai `show` dan setiap aksi tulis, supaya ID
     * milik santri di luar area pengasuh tidak pernah bisa ditebak dari URL.
     */
    public function assertAccessible(BoardingPermission $permission, ?User $actor): void
    {
        if ($actor === null) {
            return;
        }

        $this->scopes->assertCanAccessStudent($actor, (int) $permission->student_id);
    }

    /** Jumlah langkah persetujuan yang berlaku untuk permintaan baru (§27). */
    public function requiredSteps(): int
    {
        return max(1, (int) $this->settings->get('permission_approval_steps', 1));
    }

    /** Tenggat pulang (menit) sebelum kepulangan dicatat terlambat (§29). */
    public function graceMinutes(): int
    {
        return max(0, (int) $this->settings->get('permission_checkin_grace_minutes', 30));
    }

    // =====================================================================
    // Buat & ajukan
    // =====================================================================

    /**
     * Buat permintaan izin keluar (§28).
     *
     * `submit = true` menyimpan barisnya langsung sebagai `submitted` (satu
     * langkah bagi petugas di gerbang), `false` meninggalkannya `draft` untuk
     * dilengkapi — keduanya tercatat atas nama pelaku yang sama.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null, bool $submit = false): BoardingPermission
    {
        $student = $this->assertStudentPlaced($data['student_id'] ?? null, $actor);

        $startAt = $this->moment($data['start_at'] ?? null, 'start_at');
        $expectedAt = $this->moment($data['expected_return_at'] ?? null, 'expected_return_at');

        if ($expectedAt->lessThanOrEqualTo($startAt)) {
            throw ValidationException::withMessages([
                'expected_return_at' => 'Perkiraan waktu kembali harus setelah jam mulai izin (§28).',
            ]);
        }

        $this->assertNoOverlappingOpenRequest((int) $student->id, $startAt, $expectedAt);

        $status = $submit ? BoardingPermission::STATUS_SUBMITTED : BoardingPermission::STATUS_DRAFT;
        $requiredSteps = $this->requiredSteps();

        /** @var BoardingPermission $permission */
        $permission = DB::transaction(function () use ($data, $student, $actor, $startAt, $expectedAt, $status, $requiredSteps): BoardingPermission {
            $permission = BoardingPermission::create([
                'student_id' => $student->id,
                'permission_type' => $data['permission_type'] ?? BoardingPermission::TYPE_OUT,
                'destination' => $this->nullableText($data['destination'] ?? null, 150),
                'reason' => trim((string) ($data['reason'] ?? '')),
                'start_at' => $startAt,
                'expected_return_at' => $expectedAt,
                'status' => $status,
                'current_step' => 0,
                'required_steps' => $requiredSteps,
                'requested_by' => $actor?->id,
                'attachment_path' => $this->nullableText($data['attachment_path'] ?? null, 255),
                'notes' => $this->nullableText($data['notes'] ?? null, 1000),
            ]);

            $this->audit($permission, 'BOARDING_PERMISSION_CREATED', 'Izin keluar dibuat', [
                'student_id' => (int) $permission->student_id,
                'required_steps' => (int) $permission->required_steps,
            ]);

            return $permission;
        });

        if ($submit) {
            $this->notifyApprovalQueue($permission);
        }

        return $this->withDetails($permission);
    }

    /**
     * `draft` → `submitted` (§28): permintaan yang tadinya disimpan belum dibaca
     * siapa pun; sekarang masuk antrean pengesah.
     */
    public function submit(BoardingPermission $permission, ?User $actor = null): BoardingPermission
    {
        $this->assertAccessible($permission, $actor);

        $from = (string) $permission->status;

        /** @var BoardingPermission $submitted */
        $submitted = DB::transaction(function () use ($permission, $from): BoardingPermission {
            $locked = $this->lock((int) $permission->id);

            if ($locked->status !== BoardingPermission::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya izin berstatus draft yang dapat diajukan (status saat ini: '
                        .$this->statusNow($locked).') (§28).',
                ]);
            }

            // Jumlah langkah bisa berubah selama izin masih draft; diminta ulang
            // supaya antrean tidak berjalan dengan `required_steps` usang.
            $locked->forceFill([
                'status' => BoardingPermission::STATUS_SUBMITTED,
                'required_steps' => max((int) $locked->required_steps, $this->requiredSteps()),
            ])->save();

            $this->audit($locked, 'BOARDING_PERMISSION_SUBMITTED', 'Izin keluar diajukan', [
                'from' => $from,
                'to' => (string) $locked->status,
            ]);

            return $locked;
        });

        $this->notifyApprovalQueue($submitted);

        return $this->withDetails($submitted);
    }

    // =====================================================================
    // Persetujuan (§27)
    // =====================================================================

    /**
     * Setujui satu langkah persetujuan.
     *
     * Langkah pertama belum menutup permintaan bila konfigurasi meminta dua
     * langkah: status tetap `submitted` dan `current_step` naik. Langkah
     * terakhirlah yang berpindah ke `approved` lalu mengabari orang tua.
     *
     * Scope pengasuh ditegakkan di sini juga, bukan hanya di middleware rute:
     * pemegang `boarding.permission.approve` tetap hanya boleh menyetujui izin
     * santri binaannya (§54).
     */
    public function approve(BoardingPermission $permission, ?User $actor = null, ?string $note = null): BoardingPermission
    {
        $this->assertAccessible($permission, $actor);

        /** @var BoardingPermission $approved */
        $approved = DB::transaction(function () use ($permission, $actor, $note): BoardingPermission {
            $locked = $this->lock((int) $permission->id);

            $this->assertApprovable($locked);

            $step = (int) $locked->current_step + 1;
            $required = max(1, (int) $locked->required_steps);
            $isFinal = $step >= $required;

            BoardingPermissionApproval::create([
                'permission_id' => $locked->id,
                'step' => $step,
                'action' => BoardingPermissionApproval::ACTION_APPROVED,
                'acted_by' => $actor?->id,
                'acted_role' => $this->actorRole($actor),
                'note' => $this->nullableText($note, 1000),
                'acted_at' => Carbon::now(),
            ]);

            $locked->forceFill([
                'current_step' => $step,
                'status' => $isFinal ? BoardingPermission::STATUS_APPROVED : BoardingPermission::STATUS_SUBMITTED,
            ])->save();

            $this->audit($locked, 'BOARDING_PERMISSION_APPROVED', $isFinal
                ? 'Izin keluar disetujui'
                : "Langkah $step dari $required persetujuan izin keluar dicatat", [
                'step' => $step,
                'required_steps' => $required,
                'final' => $isFinal,
            ]);

            return $locked;
        });

        if ($approved->status === BoardingPermission::STATUS_APPROVED) {
            $this->notifications->toParentsOf(
                (int) $approved->student_id,
                self::NOTIFY_APPROVED,
                'Izin disetujui',
                $this->studentLabel($approved).' '.$this->typeLabel($approved).' disetujui. '
                    .'Perkiraan kembali '.($this->dateTime($approved->expected_return_at) ?? '-').'.',
                $this->context($approved),
            );
        }

        return $this->withDetails($approved);
    }

    /**
     * Tolak izin pada langkah mana pun (§27). Alasan wajib, supaya orang tua dan
     * santri tahu apa yang harus dilengkapi — bukan sekadar "ditolak".
     */
    public function reject(BoardingPermission $permission, ?User $actor = null, ?string $note = null): BoardingPermission
    {
        $this->assertAccessible($permission, $actor);

        $note = trim((string) $note);

        if (mb_strlen($note) < 5) {
            throw ValidationException::withMessages([
                'note' => 'Alasan penolakan wajib diisi (minimal 5 karakter) agar santri dan orang tua tahu apa yang perlu dilengkapi.',
            ]);
        }

        /** @var BoardingPermission $rejected */
        $rejected = DB::transaction(function () use ($permission, $actor, $note): BoardingPermission {
            $locked = $this->lock((int) $permission->id);

            if (! in_array($locked->status, [
                BoardingPermission::STATUS_DRAFT,
                BoardingPermission::STATUS_SUBMITTED,
                BoardingPermission::STATUS_APPROVED,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Izin dengan status '.$this->statusNow($locked)
                        .' sudah final dan tidak dapat ditolak lagi (§27).',
                ]);
            }

            $step = max(1, (int) $locked->current_step + 1);

            BoardingPermissionApproval::create([
                'permission_id' => $locked->id,
                'step' => $step,
                'action' => BoardingPermissionApproval::ACTION_REJECTED,
                'acted_by' => $actor?->id,
                'acted_role' => $this->actorRole($actor),
                'note' => Str::limit($note, 1000, ''),
                'acted_at' => Carbon::now(),
            ]);

            $locked->forceFill(['status' => BoardingPermission::STATUS_REJECTED])->save();

            $this->audit($locked, 'BOARDING_PERMISSION_REJECTED', 'Izin keluar ditolak', [
                'step' => $step,
                'note' => Str::limit($note, 200, ''),
            ]);

            return $locked;
        });

        $this->notifications->toParentsOf(
            (int) $rejected->student_id,
            self::NOTIFY_REJECTED,
            'Izin keluar ditolak',
            $this->studentLabel($rejected).' '.$this->typeLabel($rejected).' ditolak. Alasan: '.$note,
            $this->context($rejected),
        );

        return $this->withDetails($rejected);
    }

    // =====================================================================
    // Gerbang (§28, §29)
    // =====================================================================

    /**
     * Catat santri keluar lewat gerbang: `approved` → `out` (§28).
     *
     * Tiga syarat diperiksa pada baris yang sudah dikunci: izinnya disetujui,
     * santri punya penempatan aktif (ia memang tinggal di asrama ini), dan tidak
     * ada izin/kepulangan lain yang masih `out`.
     */
    public function checkOut(BoardingPermission $permission, ?User $actor = null, mixed $at = null): BoardingPermission
    {
        $this->assertAccessible($permission, $actor);

        $moment = $at === null ? Carbon::now() : $this->moment($at, 'checked_out_at');

        /** @var BoardingPermission $out */
        $out = DB::transaction(function () use ($permission, $actor, $moment): BoardingPermission {
            $locked = $this->lock((int) $permission->id);

            if ($locked->status !== BoardingPermission::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'status' => 'Santri hanya dapat di-check out atas izin yang sudah disetujui (status saat ini: '
                        .$this->statusNow($locked).'). Izin draft atau menunggu persetujuan belum membuka gerbang (§27).',
                ]);
            }

            $this->assertStudentInDorm((int) $locked->student_id);
            $this->assertStudentNotOutElsewhere((int) $locked->student_id, (int) $locked->id, null);

            $locked->forceFill([
                'status' => BoardingPermission::STATUS_OUT,
                'checked_out_by' => $actor?->id,
            ])->save();

            // Waktu check out disimpan pada properti audit: tabel izin tidak punya
            // kolom `checked_out_at` (§28) dan kolom tidak ditambah dari modul ini.
            $this->audit($locked, 'BOARDING_PERMISSION_CHECKED_OUT', 'Santri di-check out dari asrama', [
                'checked_out_at' => $moment->toIso8601String(),
                'checked_out_by' => $actor?->id,
            ]);

            return $locked;
        });

        $this->notifications->toParentsOf(
            (int) $out->student_id,
            self::NOTIFY_STUDENT_OUT,
            'Santri keluar dari asrama',
            $this->studentLabel($out).' keluar '.$this->typeLabel($out)
                .(filled($out->destination) ? ' ke '.$out->destination : '')
                .'. Perkiraan kembali '.($this->dateTime($out->expected_return_at) ?? '-').'.',
            $this->context($out),
        );

        return $this->withDetails($out);
    }

    /**
     * Catat santri kembali: `out` → `returned` (§29).
     *
     * `late_return` dihitung terhadap `expected_return_at` + tenggat modul, dan
     * hanya kepulangan terlambat yang dikabari — yang tepat waktu bukan peristiwa
     * yang membutuhkan tindakan orang tua.
     */
    public function checkIn(BoardingPermission $permission, ?User $actor = null, mixed $actual = null): BoardingPermission
    {
        $this->assertAccessible($permission, $actor);

        $actualReturnAt = $actual === null ? Carbon::now() : $this->moment($actual, 'actual_return_at');

        /** @var BoardingPermission $returned */
        $returned = DB::transaction(function () use ($permission, $actor, $actualReturnAt): BoardingPermission {
            $locked = $this->lock((int) $permission->id);

            if ($locked->status !== BoardingPermission::STATUS_OUT) {
                throw ValidationException::withMessages([
                    'status' => 'Check in hanya dapat dicatat untuk izin yang sedang berstatus keluar (status saat ini: '
                        .$this->statusNow($locked).') (§29).',
                ]);
            }

            $late = $this->isLate($locked, $actualReturnAt);

            $locked->forceFill([
                'status' => BoardingPermission::STATUS_RETURNED,
                'actual_return_at' => $actualReturnAt,
                'late_return' => $late,
                'checked_in_by' => $actor?->id,
            ])->save();

            $this->audit($locked, 'BOARDING_PERMISSION_CHECKED_IN', $late
                ? 'Santri kembali terlambat'
                : 'Santri kembali ke asrama', [
                'checked_in_at' => $actualReturnAt->toIso8601String(),
                'actual_return_at' => $actualReturnAt->toIso8601String(),
                'late_return' => $late,
                'checked_in_by' => $actor?->id,
            ]);

            return $locked;
        });

        if ($returned->late_return) {
            $minutes = $returned->expected_return_at === null
                ? null
                : (int) round(($actualReturnAt->getTimestamp() - $returned->expected_return_at->getTimestamp()) / 60);

            $this->notifications->toParentsOf(
                (int) $returned->student_id,
                self::NOTIFY_LATE_RETURN,
                'Santri kembali terlambat',
                $this->studentLabel($returned).' kembali '.($minutes !== null ? $minutes.' menit ' : '')
                    .'melewati perkiraan waktu kembali ('
                    .($this->dateTime($returned->expected_return_at) ?? '-').' , tenggat '
                    .$this->graceMinutes().' menit). Mohon konfirmasi kepulangannya ke pengasuh (§29).',
                $this->context($returned),
            );
        }

        return $this->withDetails($returned);
    }

    /**
     * Batalkan izin sebelum santri benar-benar keluar (§28).
     *
     * `out` sengaja tidak bisa dibatalkan: santri sudah di luar, dan satu-satunya
     * jalan menutup adalah check in.
     */
    public function cancel(BoardingPermission $permission, ?User $actor = null, ?string $note = null): BoardingPermission
    {
        $this->assertAccessible($permission, $actor);

        /** @var BoardingPermission $cancelled */
        $cancelled = DB::transaction(function () use ($permission, $actor, $note): BoardingPermission {
            $locked = $this->lock((int) $permission->id);

            if (! in_array($locked->status, [
                BoardingPermission::STATUS_DRAFT,
                BoardingPermission::STATUS_SUBMITTED,
                BoardingPermission::STATUS_APPROVED,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Izin yang sedang '.$this->statusNow($locked)
                        .' tidak dapat dibatalkan. Catat kepulangan santri lewat check in (§29).',
                ]);
            }

            $locked->forceFill([
                'status' => BoardingPermission::STATUS_CANCELLED,
                'notes' => $this->appendNote((string) $locked->notes, $note),
            ])->save();

            $this->audit($locked, 'BOARDING_PERMISSION_CANCELLED', 'Izin keluar dibatalkan', [
                'cancelled_by' => $actor?->id,
                'note' => $this->nullableText($note, 200),
            ]);

            return $locked;
        });

        $this->notifications->toParentsOf(
            (int) $cancelled->student_id,
            self::NOTIFY_CANCELLED,
            'Izin keluar dibatalkan',
            $this->studentLabel($cancelled).' '.$this->typeLabel($cancelled).' dibatalkan'
                .(filled($note) ? ': '.trim((string) $note) : '.'),
            $this->context($cancelled),
        );

        return $this->withDetails($cancelled);
    }

    /**
     * Kedaluwarsakan permintaan yang lewat jendela waktunya tanpa diselesaikan.
     *
     * `submitted` yang tak pernah disetujui dan `approved` yang santrinya tidak
     * pernah di-check out/in sama-sama berarti "izin ini tidak terpakai"; kalau
     * tidak ditutup oleh perintah harian, antrean pengasuh membengkak selamanya
     * dan laporan §42 salah membaca keberadaan santri.
     *
     * `out` TIDAK disentuh: santrinya memang sedang di luar, dan kepulangannya
     * ditutup manual lewat check in (lalu ditandai terlambat).
     *
     * Idempoten — baris yang sudah `expired` tidak dibaca ulang.
     */
    public function expireStale(): int
    {
        $candidates = BoardingPermission::query()
            ->whereIn('status', [BoardingPermission::STATUS_SUBMITTED, BoardingPermission::STATUS_APPROVED])
            ->where('expected_return_at', '<', Carbon::now())
            ->orderBy('id')
            ->pluck('id');

        $expired = 0;

        foreach ($candidates as $candidateId) {
            /** @var BoardingPermission|null $permission */
            $permission = DB::transaction(function () use ($candidateId): ?BoardingPermission {
                $locked = BoardingPermission::query()
                    ->whereKey($candidateId)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || ! in_array($locked->status, [
                    BoardingPermission::STATUS_SUBMITTED,
                    BoardingPermission::STATUS_APPROVED,
                ], true)) {
                    return null;
                }

                $locked->forceFill(['status' => BoardingPermission::STATUS_EXPIRED])->save();

                $this->audit($locked, 'BOARDING_PERMISSION_EXPIRED', 'Izin keluar kedaluwarsa', [
                    'expected_return_at' => $locked->expected_return_at?->toIso8601String(),
                ]);

                return $locked;
            });

            if ($permission === null) {
                continue;
            }

            $expired++;

            $this->notifications->toParentsOf(
                (int) $permission->student_id,
                self::NOTIFY_EXPIRED,
                'Izin keluar kedaluwarsa',
                $this->studentLabel($permission).' '.$this->typeLabel($permission)
                    .' tidak terpakai sampai batas waktunya ('
                    .($this->dateTime($permission->expected_return_at) ?? '-').') dan ditutup otomatis.',
                $this->context($permission),
            );
        }

        return $expired;
    }

    // =====================================================================
    // Penjaga keadaan
    // =====================================================================

    /**
     * Santri harus ada dan tinggal di asrama (§28): izin keluar tidak berarti
     * apa pun bagi siswa yang tidak tinggal di pondok.
     */
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
                'student_id' => 'Santri ini tidak memiliki penempatan aktif di asrama, sehingga izin keluar tidak dapat dibuat (§28).',
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

    /**
     * Tolak permintaan kedua yang jendela waktunya menimpa permintaan terbuka
     * milik santri yang sama (422, §28).
     */
    private function assertNoOverlappingOpenRequest(
        int $studentId,
        Carbon $startAt,
        Carbon $expectedAt,
        ?int $exceptId = null,
    ): void {
        $query = BoardingPermission::query()
            ->where('student_id', $studentId)
            ->whereIn('status', BoardingPermission::OPEN_STATUSES)
            ->where('start_at', '<', $expectedAt)
            ->where('expected_return_at', '>', $startAt);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        $clash = $query->orderBy('start_at')->first();

        if ($clash === null) {
            return;
        }

        throw ValidationException::withMessages([
            'start_at' => 'Santri ini sudah punya izin '.$this->statusNow($clash).' pada rentang '
                .($this->dateTime($clash->start_at) ?? '-').' sampai '.($this->dateTime($clash->expected_return_at) ?? '-')
                .'. Dua izin tidak dapat berjalan bersamaan (§28).',
        ]);
    }

    /** Santri harus benar-benar penghuni asrama saat di-check out (§42). */
    private function assertStudentInDorm(int $studentId): void
    {
        if ($this->hasActivePlacement($studentId)) {
            return;
        }

        throw ValidationException::withMessages([
            'student' => 'Santri ini tidak memiliki penempatan aktif di asrama, sehingga tidak ada yang dapat di-check out (§28).',
        ]);
    }

    /**
     * Satu santri hanya boleh keluar satu kali: izin atau kepulangan lain yang
     * masih `out` menutup gerbang (§42).
     *
     * Kedua tabel diperiksa — melewatkan `boarding_homecomings` membuat santri
     * yang sedang di rumah ikut tercatat "keluar" karena izin biasa.
     */
    public function assertStudentNotOutElsewhere(
        int $studentId,
        ?int $exceptPermissionId = null,
        ?int $exceptHomecomingId = null,
    ): void {
        $permissionQuery = BoardingPermission::query()
            ->where('student_id', $studentId)
            ->where('status', BoardingPermission::STATUS_OUT);

        if ($exceptPermissionId !== null) {
            $permissionQuery->whereKeyNot($exceptPermissionId);
        }

        if ($permissionQuery->exists()) {
            throw ValidationException::withMessages([
                'student' => 'Santri ini sudah tercatat keluar dari asrama dan belum check in. '
                    .'Selesaikan kepulangannya lebih dulu sebelum izin lain membuka gerbangnya (§42).',
            ]);
        }

        $homecomingQuery = BoardingHomecoming::query()
            ->where('student_id', $studentId)
            ->where('status', BoardingHomecoming::STATUS_OUT);

        if ($exceptHomecomingId !== null) {
            $homecomingQuery->whereKeyNot($exceptHomecomingId);
        }

        if ($homecomingQuery->exists()) {
            throw ValidationException::withMessages([
                'student' => 'Santri ini sedang tercatat pulang ke rumah (kepulangan aktif). '
                    .'Izin keluar tidak dapat di-check out sebelum ia kembali ke asrama (§30).',
            ]);
        }
    }

    private function assertApprovable(BoardingPermission $permission): void
    {
        if ($permission->status !== BoardingPermission::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => 'Hanya izin berstatus menunggu persetujuan yang dapat disetujui (status saat ini: '
                    .$this->statusNow($permission).') (§27).',
            ]);
        }

        if ($permission->isFullyApproved()) {
            throw ValidationException::withMessages([
                'step' => 'Seluruh langkah persetujuan izin ini sudah tercatat, sehingga tidak dapat disetujui lagi (§27).',
            ]);
        }
    }

    /** `actual` melewati perkiraan kembali + tenggat modul? (§29) */
    private function isLate(BoardingPermission $permission, Carbon $actual): bool
    {
        $expected = $permission->expected_return_at;

        if ($expected === null) {
            return false;
        }

        return $actual->greaterThan($expected->copy()->addMinutes($this->graceMinutes()));
    }

    /** Baris terkunci — dasar idempotensi dua petugas yang menekan bersamaan. */
    private function lock(int $id): BoardingPermission
    {
        /** @var BoardingPermission $permission */
        $permission = BoardingPermission::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        return $permission;
    }

    private function statusNow(BoardingPermission $permission): string
    {
        return BoardingPermission::STATUS_LABELS[(string) $permission->status] ?? (string) $permission->status;
    }

    // =====================================================================
    // Notifikasi & audit
    // =====================================================================

    /** Kabar ke antrean pengesah — pemegang hak, bukan broadcast (§27). */
    private function notifyApprovalQueue(BoardingPermission $permission): void
    {
        $this->notifications->toStaff(
            self::APPROVE_ABILITY,
            self::NOTIFY_SUBMITTED,
            'Izin keluar menunggu persetujuan',
            $this->studentLabel($permission).' '.$this->typeLabel($permission).' menunggu persetujuan ('
                .(int) $permission->current_step.'/'.(int) $permission->required_steps.' langkah).'
                .' Perkiraan kembali '.($this->dateTime($permission->expected_return_at) ?? '-').'.',
            $this->context($permission),
        );
    }

    /**
     * Konteks yang sama dipakai untuk menyimpan dan memeriksa duplikat
     * (`BoardingNotificationService::alreadySent()`), jadi bentuk nilainya tidak
     * boleh berubah antar panggilan — `reference_id` sengaja number, bukan string.
     *
     * @return array<string, mixed>
     */
    private function context(BoardingPermission $permission): array
    {
        return [
            'student_id' => (int) $permission->student_id,
            'reference_type' => BoardingPermission::class,
            'reference_id' => (int) $permission->id,
            'status' => (string) $permission->status,
            'permission_type' => (string) $permission->permission_type,
        ];
    }

    private function studentLabel(BoardingPermission $permission): string
    {
        $permission->loadMissing('student:id,full_name');

        return (string) ($permission->student?->full_name ?? ('santri #'.$permission->student_id));
    }

    private function typeLabel(BoardingPermission $permission): string
    {
        return 'izin '.strtolower((string) $permission->typeLabel());
    }

    /**
     * Jejak audit eksplisit di samping catatan otomatis model (§57): nama aksi
     * bergaya spesifikasi dipakai laporan audit dan menyimpan waktu gerbang yang
     * tidak punya kolom di tabel izin.
     *
     * @param  array<string, mixed>  $properties
     */
    private function audit(BoardingPermission $permission, string $event, string $description, array $properties = []): void
    {
        activity('boarding')
            ->event($event)
            ->performedOn($permission)
            ->withProperties($properties + ['status' => (string) $permission->status])
            ->log($description);
    }

    /**
     * Waktu check out/in per id izin, dibaca dari jejak audit (§28): satu query
     * untuk seluruh baris pada halaman detail, jadi tidak ada N+1 di daftar.
     *
     * @param  list<int>  $ids
     * @return array<int, array{checked_out_at: ?string, checked_in_at: ?string}>
     */
    private function gateMomentsFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = Activity::query()
            ->where('log_name', 'boarding')
            ->where('subject_type', BoardingPermission::class)
            ->whereIn('subject_id', $ids)
            ->whereIn('event', ['BOARDING_PERMISSION_CHECKED_OUT', 'BOARDING_PERMISSION_CHECKED_IN'])
            ->orderBy('id')
            ->get(['subject_id', 'event', 'properties']);

        $moments = [];

        foreach ($rows as $row) {
            $id = (int) $row->subject_id;
            $properties = is_array($row->properties) ? $row->properties : [];

            if ($row->event === 'BOARDING_PERMISSION_CHECKED_OUT') {
                $moments[$id]['checked_out_at'] = $properties['checked_out_at'] ?? $row->created_at?->toIso8601String();
            } else {
                $moments[$id]['checked_in_at'] = $properties['checked_in_at'] ?? $properties['actual_return_at'] ?? $row->created_at?->toIso8601String();
            }
        }

        return $moments;
    }

    /** Peran pelaku saat bertindak — dibaca dari model, bukan dari klien. */
    private function actorRole(?User $actor): ?string
    {
        if ($actor === null) {
            return null;
        }

        $names = $actor->roles?->pluck('name')->all() ?? [];

        return $names === [] ? null : Str::limit(implode(',', $names), 100, '');
    }

    // =====================================================================
    // Utilitas
    // =====================================================================

    /**
     * @return list<string>
     */
    private function eagerLoads(): array
    {
        return ['student:id,full_name,nis,nisn'];
    }

    /**
     * @return list<string>
     */
    private function detailLoads(): array
    {
        return [
            'student:id,full_name,nis,nisn',
            'approvals.actedBy:id,name',
            'requestedBy:id,name',
            'checkedOutBy:id,name',
            'checkedInBy:id,name',
        ];
    }

    /**
     * Santri yang menempati satu area (asrama/kamar) — subquery, karena tabel
     * izin tidak menyimpan lokasi santri.
     *
     * @return list<int>
     */
    private function studentIdsOnStructure(string $column, int $value): array
    {
        return BoardingPlacement::query()
            ->active()
            ->where($column, $value)
            ->pluck('student_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Tanggal/waktu dari klien (`Request`) atau pemanggil non-HTTP. Format salah
     * berujung 422 berkoncal, bukan 500 dari `Carbon::parse`.
     */
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
            throw ValidationException::withMessages([$field => 'Waktu wajib diisi.']);
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $field => 'Format waktu "'.Str::limit($value, 40).' " tidak dikenali. Gunakan contoh: 2026-09-25 15:30.',
            ]);
        }
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $max, '');
    }

    /** Catatan pembatalan tidak menimpa catatan lama — riwayat tetap terbaca. */
    private function appendNote(string $existing, ?string $extra): ?string
    {
        $extra = trim((string) $extra);

        if ($extra === '') {
            return $this->nullableText($existing, 1000);
        }

        $line = '['.Carbon::now()->format('d/m/Y H:i').'] '.$extra;

        $joined = $existing === '' ? $line : $existing.' | '.$line;

        return Str::limit($joined, 1000, '');
    }

    private function dateTime(mixed $value): ?string
    {
        return $value instanceof Carbon ? $value->format('d/m/Y H:i') : null;
    }
}
