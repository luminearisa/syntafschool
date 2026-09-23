<?php

namespace App\Services\Wallet;

use App\Models\Employee;
use App\Models\Guardian;
use App\Models\PpdbParentAccount;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Ciclo hidup wallet: dibuat, dicari, diblokir, ditutup — TANPA menyentuh saldo.
 *
 * Kelas ini pemilik aturan "tepat satu orang punya satu wallet" (§3) dan satu-satunya
 * tempat yang boleh menulis kolom identitas/status wallet. Kolom `balance` dan
 * `held_balance` tidak pernah disentuh di sini: hanya `WalletLedgerService` yang
 * memindahkannya (§8). Wallet blocked/closed ditolak untuk top up maupun penarikan
 * lewat `assertCanTransact()`, yang dipakai irisan transaksi (§21).
 *
 * Pembuatan wallet disengaja TIDAK otomatis saat santri/karyawan dibuat (§4A):
 * `ensureForOwner()` menyediakannya saat pemilik pertama membutuhkannya, sehingga
 * sekolah yang tidak memakai wallet tidak menumpuk rekening kosong.
 */
class WalletService
{
    /** Tipe notifikasi in-app (§23) — dipakai frontend untuk memilih ikon/tautan. */
    public const NOTIFICATION_BLOCKED = 'wallet_blocked';

    public const NOTIFICATION_UNBLOCKED = 'wallet_unblocked';

    public const NOTIFICATION_CLOSED = 'wallet_closed';

    /** Master person per tipe pemilik: [model, tabel]. */
    private const OWNERS = [
        Wallet::TYPE_STUDENT => [Student::class, 'students'],
        Wallet::TYPE_EMPLOYEE => [Employee::class, 'employees'],
        Wallet::TYPE_GUARDIAN => [Guardian::class, 'guardians'],
        Wallet::TYPE_USER => [User::class, 'users'],
    ];

    /** Urutan identitas yang dipilih saat pemilik tidak menyebutkannya. */
    private const IDENTITY_PRIORITY = [
        Wallet::TYPE_STUDENT, Wallet::TYPE_EMPLOYEE, Wallet::TYPE_GUARDIAN, Wallet::TYPE_USER,
    ];

    public function __construct(
        private readonly WalletNumberingService $numbering,
        private readonly WalletNotificationService $notifications,
        private readonly WalletRfidService $rfid,
    ) {
    }

    // =====================================================================
    // PEMBACAAN
    // =====================================================================

    /**
     * Daftar wallet pejabat/admin (§5).
     *
     * `search` menutup nomor rekening, nama pemilik, NIS/NISN, NIP, ID pemilik
     * (termasuk parent id), dan UID/séri kartu RFID.
     *
     * @return Builder<Wallet>
     */
    public function query(Request $request): Builder
    {
        return $this->applyFilters(
            Wallet::query()->with(array_keys(self::OWNERS)),
            $request,
            (string) $request->string('search')->toString(),
        )->latest('id');
    }

    /**
     * Wallet yang boleh dilihat pemanggil di portal: miliknya sendiri plus wallet
     * anak-anak yang terhubung (§23, §24, §25).
     *
     * Anak dibaca dari `student_guardians` milik wali pemanggil, jadi wallet anak
     * yang walinya orang lain tidak pernah ikut terbaca.
     *
     * @return Builder<Wallet>
     */
    public function portalQuery(User $user, Request $request): Builder
    {
        $visible = $this->visibleOwnerIds($user);

        $query = Wallet::query()
            ->with(array_keys(self::OWNERS))
            ->where(function (Builder $scope) use ($visible): void {
                foreach ($visible as $type => $ids) {
                    if ($ids !== []) {
                        $scope->orWhereIn(Wallet::OWNER_COLUMNS[$type], $ids);
                    }
                }
            });

        return $this->applyFilters($query, $request, (string) $request->string('search')->toString())
            ->latest('id');
    }

    /**
     * Wallet detail beserta pemilik, kartu RFID, dan pembuatnya (§22).
     */
    public function withRelations(Wallet $wallet): Wallet
    {
        return $wallet->loadMissing([
            'student', 'employee', 'guardian', 'user', 'createdBy', 'rfidCards',
        ]);
    }

    /**
     * Buku besar wallet sebagai baris datar siap tampil (§9, §22).
     *
     * Baris ledger append-only: hanya dibaca. `$staff = false` membuang kolom
     * internal (siapa petugasnya, payload rujukan) karena yang bertanya adalah
     * pemilik saldo, bukan pejabat.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function ledger(Wallet $wallet, Request $request, bool $staff = true): LengthAwarePaginator
    {
        $like = '%'.trim((string) $request->string('search')->toString()).'%';

        $rows = $wallet->transactions()
            ->when($request->filled('type'), fn ($q) => $q->where('type', (string) $request->input('type')))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', (string) $request->input('direction')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('posted_at', '>=', (string) $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('posted_at', '<=', (string) $request->input('date_to')))
            ->when($request->filled('search'), fn ($q) => $q->where(function (Builder $scope) use ($like): void {
                $scope->where('transaction_number', 'like', $like)
                    ->orWhere('description', 'like', $like);
            }))
            ->orderByDesc('id');

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $rows->paginate($perPage)->appends($request->query())->through(
            fn (WalletTransaction $transaction): array => $this->ledgerRow($transaction, $staff),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function ledgerRow(WalletTransaction $transaction, bool $staff): array
    {
        $row = [
            'id' => (int) $transaction->id,
            'wallet_id' => (int) $transaction->wallet_id,
            'transaction_number' => $transaction->transaction_number,
            'type' => $transaction->type,
            'type_label' => $this->transactionTypeLabel($transaction->type),
            'direction' => $transaction->direction,
            'amount' => round((float) $transaction->amount, 2),
            'signed_amount' => $transaction->signedAmount(),
            'balance_before' => round((float) $transaction->balance_before, 2),
            'balance_after' => round((float) $transaction->balance_after, 2),
            'status' => $transaction->status,
            'description' => $transaction->description,
            'posted_at' => $transaction->posted_at?->toIso8601String(),
            'created_at' => $transaction->created_at?->toIso8601String(),
        ];

        if ($staff) {
            $row['reference_type'] = $transaction->reference_type;
            $row['reference_id'] = $transaction->reference_id === null ? null : (int) $transaction->reference_id;
            $row['source_transaction_id'] = $transaction->source_transaction_id === null ? null : (int) $transaction->source_transaction_id;
            $row['created_by'] = $transaction->created_by === null ? null : (int) $transaction->created_by;
            $row['approved_by'] = $transaction->approved_by === null ? null : (int) $transaction->approved_by;
            $row['approved_at'] = $transaction->approved_at?->toIso8601String();
            $row['metadata'] = $transaction->metadata;
        }

        return $row;
    }

    private function transactionTypeLabel(string $type): string
    {
        return match ($type) {
            WalletTransaction::TYPE_TOPUP => 'Top up',
            WalletTransaction::TYPE_WITHDRAW => 'Penarikan',
            WalletTransaction::TYPE_ADJUSTMENT_CREDIT => 'Penyesuaian tambahan',
            WalletTransaction::TYPE_ADJUSTMENT_DEBIT => 'Penyesuaian pengurang',
            WalletTransaction::TYPE_REFUND => 'Pengembalian dana',
            WalletTransaction::TYPE_REVERSAL => 'Pembatalan transaksi',
            default => $type,
        };
    }

    /**
     * Filter bersama untuk daftar admin & portal.
     *
     * @param  Builder<Wallet>  $query
     */
    private function applyFilters(Builder $query, Request $request, string $search): Builder
    {
        $like = '%'.trim($search).'%';

        return $query
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->input('status')))
            ->when(
                $request->filled('owner_type'),
                fn (Builder $q) => $q->ofType((string) $request->input('owner_type')),
            )
            ->when($request->filled('student_id'), fn (Builder $q) => $q->where('student_id', (int) $request->input('student_id')))
            ->when($request->filled('employee_id'), fn (Builder $q) => $q->where('employee_id', (int) $request->input('employee_id')))
            ->when($request->filled('guardian_id'), fn (Builder $q) => $q->where('guardian_id', (int) $request->input('guardian_id')))
            ->when($request->filled('user_id'), fn (Builder $q) => $q->where('user_id', (int) $request->input('user_id')))
            ->when($request->boolean('has_rfid'), fn (Builder $q) => $q->has('rfidCards'))
            ->when($search !== '', function (Builder $q) use ($like, $search): void {
                $q->where(function (Builder $scope) use ($like, $search): void {
                    $scope->where('number', 'like', $like)
                        ->orWhereHas('student', fn (Builder $s) => $s
                            ->where('full_name', 'like', $like)
                            ->orWhere('nis', 'like', $like)
                            ->orWhere('nisn', 'like', $like))
                        ->orWhereHas('employee', fn (Builder $e) => $e
                            ->where('full_name', 'like', $like)
                            ->orWhere('nip', 'like', $like))
                        ->orWhereHas('guardian', fn (Builder $g) => $g
                            ->where('full_name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like))
                        ->orWhereHas('user', fn (Builder $u) => $u
                            ->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('username', 'like', $like))
                        ->orWhereHas('rfidCards', fn (Builder $c) => $c
                            ->where('uid', 'like', $like)
                            ->orWhere('serial_number', 'like', $like));

                    // "Parent ID" / ID pemilik ikut dicari bila angkanya murni (§5).
                    if (ctype_digit($search)) {
                        $id = (int) $search;
                        $scope->orWhere('student_id', $id)
                            ->orWhere('employee_id', $id)
                            ->orWhere('guardian_id', $id)
                            ->orWhere('user_id', $id);
                    }
                });
            });
    }

    // =====================================================================
    // PEMBUATAN
    // =====================================================================

    /**
     * Wallet baru untuk tepat satu pemilik (§3, §4B, §5).
     *
     * Nomor rekening terbit otomatis dan tidak pernah berubah; saldo lahir 0 dan
     * hanya boleh bergerak lewat ledger.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Wallet
    {
        $type = $this->assertSingleOwner($data);
        $ownerId = (int) $data[Wallet::OWNER_COLUMNS[$type]];

        $this->assertOwnerExists($type, $ownerId);
        $this->assertOwnerHasNoWallet($type, $ownerId);

        return DB::transaction(function () use ($type, $ownerId, $data, $actor): Wallet {
            // Periksa kedua kalinya di dalam transaksi: dua permintaan paralel
            // tidak boleh melahirkan dua rekening untuk satu orang.
            $this->assertOwnerHasNoWallet($type, $ownerId);

            return Wallet::create([
                'number' => $this->numbering->next(WalletNumberingService::CODE_WALLET),
                Wallet::OWNER_COLUMNS[$type] => $ownerId,
                'balance' => 0,
                'held_balance' => 0,
                'status' => Wallet::STATUS_ACTIVE,
                'opened_at' => now(),
                'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
                'created_by' => $actor?->id,
            ]);
        });
    }

    /**
     * Wallet pemilik, dibuat pada saat pertama dibutuhkan (§4A).
     *
     * Idempoten: dipanggil dua kali menghasilkan satu wallet yang sama, sehingga
     * jalur portal, kantin, dan penugasan RFID boleh memanggilnya tanpa saling
     * menunggu.
     */
    public function ensureForOwner(string $ownerType, int $ownerId, ?User $actor = null): Wallet
    {
        $this->assertOwnerType($ownerType);
        $this->assertOwnerExists($ownerType, $ownerId);

        $existing = Wallet::query()
            ->where(Wallet::OWNER_COLUMNS[$ownerType], $ownerId)
            ->orderBy('id')
            ->first();

        return $existing ?? $this->create([Wallet::OWNER_COLUMNS[$ownerType] => $ownerId], $actor);
    }

    /**
     * Wallet milik pemanggil portal sendiri.
     *
     * Tidak ada `owner_id` yang diterima dari portal: identitas dibaca dari akun
     * pemanggil, jadi endpoint ini tidak bisa dipakai membuatkan wallet orang lain.
     */
    public function ensureForUser(User $user, ?string $ownerType = null): Wallet
    {
        $identities = $this->identitiesOf($user);

        if ($ownerType === null) {
            foreach (self::IDENTITY_PRIORITY as $type) {
                if ($identities[$type] !== []) {
                    return $this->ensureForOwner($type, $identities[$type][0], $user);
                }
            }

            throw new HttpException(403,
                'Akun Anda belum terhubung ke data santri, karyawan, atau wali. '
                .'Hubungi bagian administrasi untuk menautkannya.'
            );
        }

        $this->assertOwnerType($ownerType);

        if ($identities[$ownerType] === []) {
            throw new HttpException(403,
                'Anda tidak memiliki identitas sebagai '.self::ownerTypeLabel($ownerType)
                .', sehingga wallet atas nama itu tidak dapat dibuatkan.'
            );
        }

        return $this->ensureForOwner($ownerType, $identities[$ownerType][0], $user);
    }

    // =====================================================================
    // PERUBAHAN
    // =====================================================================

    /**
     * Hanya catatan internal admin (§4B). Pemilik, nomor, dan saldo tidak dapat
     * dipindahkan dari sini.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Wallet $wallet, array $data, ?User $actor = null): Wallet
    {
        $notes = isset($data['notes']) ? (string) $data['notes'] : null;

        $wallet->forceFill(['notes' => $notes === '' ? null : $notes])->save();

        activity('wallet')
            ->event('WALLET_UPDATED')
            ->performedOn($wallet)
            ->causedBy($actor)
            ->withProperties(['notes' => $wallet->notes])
            ->log(sprintf('Catatan wallet %s diperbarui oleh %s.', $wallet->number, (string) $actor?->name));

        return $this->withRelations($wallet->refresh());
    }

    /**
     * Blokir: saldo tetap tersimpan, wallet tidak dapat dipakai (§21).
     *
     * @param  array{reason?: string|null, block_rfid_cards?: bool}  $data
     */
    public function block(Wallet $wallet, array $data, ?User $actor = null): Wallet
    {
        $reason = $this->assertReason($data['reason'] ?? null);

        if ($wallet->status === Wallet::STATUS_BLOCKED) {
            throw ValidationException::withMessages([
                'status' => "Wallet {$wallet->number} sudah dalam keadaan diblokir.",
            ]);
        }

        $this->assertNotClosed($wallet, 'diblokir');

        $cardsBlocked = 0;

        DB::transaction(function () use ($wallet, $reason, $data, $actor, &$cardsBlocked): void {
            $wallet->forceFill([
                'status' => Wallet::STATUS_BLOCKED,
                'blocked_at' => now(),
                'block_reason' => $reason,
            ])->save();

            // §21: "RFID terkait dapat ikut diblokir sesuai policy" — opsional,
            // penyelia yang memutuskan lewat permintaan.
            if ($this->truthy($data['block_rfid_cards'] ?? false)) {
                $cardsBlocked = $this->rfid->blockAllForWallet($wallet, $reason, $actor);
            }

            activity('wallet')
                ->event('WALLET_BLOCKED')
                ->performedOn($wallet)
                ->causedBy($actor)
                ->withProperties([
                    'previous_status' => Wallet::STATUS_ACTIVE,
                    'reason' => $reason,
                    'rfid_cards_blocked' => $cardsBlocked,
                ])
                ->log(sprintf('Wallet %s diblokir oleh %s: %s', $wallet->number, (string) $actor?->name, $reason));
        });

        $this->notifications->toUser(
            $this->ownerUserId($wallet),
            self::NOTIFICATION_BLOCKED,
            'Wallet diblokir',
            sprintf('Wallet %s Anda diblokir oleh petugas. Alasan: %s', $wallet->number, $reason),
            ['wallet_id' => $wallet->id, 'status' => Wallet::STATUS_BLOCKED],
        );

        return $this->withRelations($wallet->refresh());
    }

    /**
     * Buka blokir: wallet kembali aktif (§21).
     *
     * Kartu yang ikut diblokir TIDAK diaktifkan otomatis — kartu punya nasibnya
     * sendiri (mungkin memang hilang), dan mengaktifkan kembali kartu hilang
     * adalah lubang keamanan yang tidak mau dibuka siapa pun.
     *
     * @param  array<string, mixed>  $data
     */
    public function unblock(Wallet $wallet, array $data, ?User $actor = null): Wallet
    {
        $this->assertNotClosed($wallet, 'dibuka blokirnya');

        if ($wallet->status !== Wallet::STATUS_BLOCKED) {
            throw ValidationException::withMessages([
                'status' => "Wallet {$wallet->number} tidak sedang diblokir, jadi tidak ada yang perlu dibuka.",
            ]);
        }

        $reason = isset($data['reason']) && trim((string) $data['reason']) !== ''
            ? trim((string) $data['reason'])
            : null;

        $previous = (string) $wallet->status;

        $wallet->forceFill([
            'status' => Wallet::STATUS_ACTIVE,
            'blocked_at' => null,
            'block_reason' => null,
        ])->save();

        activity('wallet')
            ->event('WALLET_UNBLOCKED')
            ->performedOn($wallet)
            ->causedBy($actor)
            ->withProperties(['previous_status' => $previous, 'reason' => $reason])
            ->log(sprintf(
                'Wallet %s dibuka blokirnya oleh %s%s.',
                $wallet->number,
                (string) $actor?->name,
                $reason === null ? '' : " ({$reason})",
            ));

        $this->notifications->toUser(
            $this->ownerUserId($wallet),
            self::NOTIFICATION_UNBLOCKED,
            'Wallet aktif kembali',
            sprintf('Wallet %s Anda sudah dapat digunakan kembali.', $wallet->number),
            ['wallet_id' => $wallet->id, 'status' => Wallet::STATUS_ACTIVE],
        );

        return $this->withRelations($wallet->refresh());
    }

    /**
     * Tutup permanen (§2). Saldo tetap ada dan suatu saat ditarik/disesuaikan
     * lewat ledger — penutupan tidak pernah menghapus uang.
     *
     * @param  array<string, mixed>  $data
     */
    public function close(Wallet $wallet, array $data, ?User $actor = null): Wallet
    {
        $reason = $this->assertReason($data['reason'] ?? null);

        if ($wallet->status === Wallet::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'status' => "Wallet {$wallet->number} sudah ditutup.",
            ]);
        }

        $previous = (string) $wallet->status;

        DB::transaction(function () use ($wallet, $reason, $previous, $actor): void {
            // Skema tidak punya `close_reason`: `block_reason` dipakai sebagai
            // tempat alasan penutupan, karena keduanya sama-sama "kenapa wallet
            // ini tidak dapat dipakai".
            $wallet->forceFill([
                'status' => Wallet::STATUS_CLOSED,
                'closed_at' => now(),
                'block_reason' => $reason,
            ])->save();

            // Rekening mati: kartunya juga tidak boleh menunjuk apa pun lagi.
            $this->rfid->deactivateAllForWallet($wallet, $reason, $actor);

            activity('wallet')
                ->event('WALLET_CLOSED')
                ->performedOn($wallet)
                ->causedBy($actor)
                ->withProperties(['previous_status' => $previous, 'reason' => $reason])
                ->log(sprintf('Wallet %s ditutup permanen oleh %s: %s', $wallet->number, (string) $actor?->name, $reason));
        });

        $this->notifications->toUser(
            $this->ownerUserId($wallet),
            self::NOTIFICATION_CLOSED,
            'Wallet ditutup',
            sprintf('Wallet %s Anda ditutup permanen oleh petugas. Alasan: %s', $wallet->number, $reason),
            ['wallet_id' => $wallet->id, 'status' => Wallet::STATUS_CLOSED],
        );

        return $this->withRelations($wallet->refresh());
    }

    // =====================================================================
    // PENJAGA TRANSAKSI (dipakai irisan top up / withdrawal / transaksi)
    // =====================================================================

    /**
     * Gerbang status untuk setiap gerakan uang (§21).
     *
     * Dipakai `WalletTopUpService` dan `WalletWithdrawalService` sebelum nominal
     * apa pun dicatat, sehingga wallet blocked/closed tidak pernah bisa menambah
     * atau mengurangi saldo.
     */
    public function assertCanTransact(Wallet $wallet): Wallet
    {
        if ($wallet->canTransact()) {
            return $wallet;
        }

        $reason = $wallet->block_reason === null ? '' : " Alasan: {$wallet->block_reason}";

        throw ValidationException::withMessages([
            'wallet' => match ($wallet->status) {
                Wallet::STATUS_BLOCKED => "Wallet {$wallet->number} sedang diblokir sehingga top up dan penarikan tidak dapat diproses.{$reason}",
                Wallet::STATUS_CLOSED => "Wallet {$wallet->number} sudah ditutup permanen sehingga tidak dapat dipakai bertransaksi lagi.{$reason}",
                default => "Wallet {$wallet->number} sedang tidak aktif sehingga tidak dapat dipakai bertransaksi.",
            },
        ]);
    }

    /** Wallet milik pemanggil portal, atau 403 (§24). */
    public function assertPortalWallet(User $user, Wallet $wallet): Wallet
    {
        $visible = $this->visibleOwnerIds($user);
        $type = $wallet->ownerType();

        if ($type === null || ! in_array((int) $wallet->getAttribute(Wallet::OWNER_COLUMNS[$type]), $visible[$type], true)) {
            throw new HttpException(403, 'Wallet tersebut bukan milik Anda atau anak Anda.');
        }

        return $wallet;
    }

    // =====================================================================
    // PEMILIK
    // =====================================================================

    /**
     * Identitas person yang melekat pada satu akun: santri/karyawan lewat
     * `users.userable`, wali lewat akun PPDB yang sudah ada, plus dirinya sendiri.
     *
     * @return array<string, list<int>>
     */
    public function identitiesOf(User $user): array
    {
        $userable = $user->userable;

        return [
            Wallet::TYPE_STUDENT => $userable instanceof Student ? [(int) $userable->id] : [],
            Wallet::TYPE_EMPLOYEE => $userable instanceof Employee ? [(int) $userable->id] : [],
            Wallet::TYPE_GUARDIAN => PpdbParentAccount::query()
                ->where('user_id', $user->id)
                ->whereNotNull('guardian_id')
                ->pluck('guardian_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all(),
            Wallet::TYPE_USER => [(int) $user->id],
        ];
    }

    /**
     * ID pemilik per tipe yang boleh dibaca pemanggil: dirinya sendiri plus
     * anak-anak walinya (§24, §25).
     *
     * @return array<string, list<int>>
     */
    public function visibleOwnerIds(User $user): array
    {
        $ids = $this->identitiesOf($user);

        if ($ids[Wallet::TYPE_GUARDIAN] !== []) {
            $children = Student::query()
                ->whereIn('id', DB::table('student_guardians')
                    ->whereIn('guardian_id', $ids[Wallet::TYPE_GUARDIAN])
                    ->pluck('student_id')
                    ->unique()
                    ->all())
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $ids[Wallet::TYPE_STUDENT] = array_values(array_unique([...$ids[Wallet::TYPE_STUDENT], ...$children]));
        }

        return $ids;
    }

    /**
     * Wallet anak-anak pemanggil — dipakai portal untuk memisahkan "wallet saya"
     * dan "wallet anak" tanpa membuat frontend menebak (§25).
     *
     * @return list<int>
     */
    public function childWalletIds(User $user): array
    {
        $own = $this->identitiesOf($user);
        $children = array_values(array_diff(
            $this->visibleOwnerIds($user)[Wallet::TYPE_STUDENT],
            $own[Wallet::TYPE_STUDENT],
        ));

        if ($children === []) {
            return [];
        }

        return Wallet::query()
            ->whereIn('student_id', $children)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Hubungan pemanggil dengan satu wallet: `own` atau `child` (§24, §25).
     */
    public function portalRelationship(User $user, Wallet $wallet): string
    {
        $type = $wallet->ownerType();

        if ($type === null) {
            return 'own';
        }

        $own = $this->identitiesOf($user);
        $ownerId = (int) $wallet->getAttribute(Wallet::OWNER_COLUMNS[$type]);

        $isOwn = $type === Wallet::TYPE_STUDENT
            ? in_array($ownerId, $own[Wallet::TYPE_STUDENT], true)
            : in_array($ownerId, $own[$type], true);

        return $isOwn ? 'own' : 'child';
    }

    /**
     * Akun portal pemilik wallet, bila ia memang punya akun login.
     *
     * Wali tidak punya kolom `user_id` pada master-nya: tautannya dijaga
     * `ppdb_parent_accounts`, jadi relasi itu dipakai ulang dan tidak ada tabel
     * akun baru.
     */
    public function ownerUserId(Wallet $wallet): ?int
    {
        return match ($wallet->ownerType()) {
            Wallet::TYPE_USER => (int) $wallet->user_id,
            Wallet::TYPE_STUDENT => User::query()
                ->where('userable_type', Student::class)->where('userable_id', $wallet->student_id)->value('id'),
            Wallet::TYPE_EMPLOYEE => User::query()
                ->where('userable_type', Employee::class)->where('userable_id', $wallet->employee_id)->value('id'),
            Wallet::TYPE_GUARDIAN => PpdbParentAccount::query()
                ->where('guardian_id', $wallet->guardian_id)->value('user_id'),
            default => null,
        };
    }

    public static function ownerTypeLabel(string $type): string
    {
        return match ($type) {
            Wallet::TYPE_STUDENT => 'Santri',
            Wallet::TYPE_EMPLOYEE => 'Karyawan',
            Wallet::TYPE_GUARDIAN => 'Orang tua/Wali',
            Wallet::TYPE_USER => 'Pengguna',
            default => $type,
        };
    }

    /** Kolom FK pemilik untuk satu tipe, atau null bila tipenya asing. */
    public static function ownerColumn(?string $type): ?string
    {
        return $type === null ? null : (Wallet::OWNER_COLUMNS[$type] ?? null);
    }

    /**
     * Pemanggil ini pejabat wallet atau pemilik saldo?
     *
     * Satu-satunya pembeda antara tampilan admin (§22) dan portal (§23): catatan
     * internal, identitas petugas, dan UID kartu penuh hanya untuk pemegang
     * `wallet.view`. Portal tidak memegang permission apa pun.
     */
    public static function staffView(Request $request): bool
    {
        return (bool) $request->user()?->hasAnyPermission(['wallet.view']);
    }

    /**
     * UID kartu untuk layar pemilik: `****1234` (§23).
     *
     * Nomor rekening tidak dirahasiakan karena pemilik memang perlu
     * menyerahkannya; UID kartu fisik tidak, karena itulah satu-satunya yang
     * membuat kartu bisa dipakai orang lain bila nomornya bocor.
     */
    public static function maskedUid(?string $uid): ?string
    {
        if ($uid === null || $uid === '') {
            return null;
        }

        return strlen($uid) > 4
            ? '****'.substr($uid, -4)
            : '****';
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            Wallet::STATUS_ACTIVE => 'Aktif',
            Wallet::STATUS_BLOCKED => 'Diblokir',
            Wallet::STATUS_CLOSED => 'Ditutup',
            default => $status,
        };
    }

    // =====================================================================
    // PENJAGA INTERNAL
    // =====================================================================

    /**
     * Tepat satu pemilik: bukan nol, bukan dua (§3).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertSingleOwner(array $data): string
    {
        $filled = [];
        foreach (Wallet::OWNER_COLUMNS as $type => $column) {
            if (isset($data[$column]) && $data[$column] !== '' && $data[$column] !== 0 && $data[$column] !== '0') {
                $filled[] = $type;
            }
        }

        if (count($filled) === 0) {
            throw ValidationException::withMessages([
                'student_id' => 'Pilih pemilik wallet: santri, karyawan, orang tua/wali, atau pengguna.',
            ]);
        }

        if (count($filled) > 1) {
            throw ValidationException::withMessages([
                'owner_type' => 'Satu wallet hanya boleh memiliki satu pemilik ('
                    .implode(', ', array_map([self::class, 'ownerTypeLabel'], $filled)).').',
            ]);
        }

        $type = $filled[0];

        $declared = $data['owner_type'] ?? null;

        if ($declared !== null && $declared !== '' && $declared !== $type) {
            throw ValidationException::withMessages([
                'owner_type' => 'Tipe pemilik tidak cocok dengan data pemilik yang dikirim '
                    .'(terkirim: '.self::ownerTypeLabel((string) $declared).', pemilik: '.self::ownerTypeLabel($type).').',
            ]);
        }

        return $type;
    }

    private function assertOwnerType(string $type): void
    {
        if (! isset(self::OWNERS[$type])) {
            throw ValidationException::withMessages([
                'owner_type' => 'Tipe pemilik tidak dikenal. Pilihan: '.implode(', ', Wallet::OWNER_TYPES).'.',
            ]);
        }
    }

    private function assertOwnerExists(string $type, int $ownerId): void
    {
        [$model] = self::OWNERS[$type];

        if ($model::query()->whereKey($ownerId)->doesntExist()) {
            throw ValidationException::withMessages([
                Wallet::OWNER_COLUMNS[$type] => self::ownerTypeLabel($type)
                    .' dengan ID '.$ownerId.' tidak ditemukan.',
            ]);
        }
    }

    /** Satu orang satu rekening: wallet kedua untuk orang yang sama ditolak. */
    private function assertOwnerHasNoWallet(string $type, int $ownerId): void
    {
        $existing = Wallet::query()->where(Wallet::OWNER_COLUMNS[$type], $ownerId)->orderBy('id')->first();

        if ($existing === null) {
            return;
        }

        throw ValidationException::withMessages([
            Wallet::OWNER_COLUMNS[$type] => self::ownerTypeLabel($type).' ini sudah memiliki wallet '
                .$existing->number.' (status '.self::statusLabel($existing->status).'). '
                .'Satu orang hanya boleh punya satu wallet.',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function assertReason(?string $reason): string
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan wajib diisi agar tindakan atas uang orang lain dapat dipertanggungjawabkan.',
            ]);
        }

        return $reason;
    }

    /** Wallet tertutup tidak dapat berubah status lagi. */
    private function assertNotClosed(Wallet $wallet, string $verb): void
    {
        if ($wallet->status === Wallet::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'status' => "Wallet {$wallet->number} sudah ditutup permanen dan tidak dapat {$verb} lagi.",
            ]);
        }
    }

    private function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }
}
