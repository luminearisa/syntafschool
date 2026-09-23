<?php

namespace App\Services\Wallet;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRfidCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kartu RFID: identitas fisik yang menunjuk ke satu wallet (§6, §7).
 *
 * Aturan yang dijaga di sini:
 * - UID unik GLOBAL. Satu kartu fisik tidak boleh menunjuk dua rekening — kalau
 *   boleh, tap di kantin tidak pernah bisa dipercaya.
 * - Mengganti kartu TIDAK membuat wallet baru dan TIDAK memindahkan saldo: kartu
 *   lama ditandai `lost`/`blocked`/`replaced` dan menunjuk penggantinya, sehingga
 *   riwayat kartu tetap terbaca (§6).
 * - Kartu tidak pernah dihapus. Yang ada hanya pergantian status.
 *
 * Kelas ini tidak menyentuh saldo dan tidak mengimpor `WalletService` (WalletService
 * yang memanggil ke sini saat memblokir/menutup wallet) — arah dependensi sengaja
 * satu jalur supaya keduanya bisa di-unit-test terpisah.
 */
class WalletRfidService
{
    /** Status yang boleh diminta lewat endpoint "ubah status". */
    public const SETTABLE_STATUSES = [
        WalletRfidCard::STATUS_ACTIVE,
        WalletRfidCard::STATUS_INACTIVE,
        WalletRfidCard::STATUS_BLOCKED,
        WalletRfidCard::STATUS_LOST,
    ];

    /**
     * Terbitkan dan tautkan satu UID ke wallet.
     *
     * @param  array{uid: string, serial_number?: string|null, issuing_batch?: string|null, notes?: string|null}  $data
     */
    public function assign(Wallet $wallet, array $data, ?User $actor = null): WalletRfidCard
    {
        if ($wallet->status === Wallet::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'wallet' => "Wallet {$wallet->number} sudah ditutup sehingga kartu baru tidak dapat diterbitkan.",
            ]);
        }

        $uid = self::normalizeUid((string) $data['uid']);

        return DB::transaction(function () use ($wallet, $uid, $data, $actor): WalletRfidCard {
            $this->assertUidAvailable($uid);

            $card = WalletRfidCard::create([
                'uid' => $uid,
                'serial_number' => $this->blankToNull($data['serial_number'] ?? null),
                'wallet_id' => $wallet->id,
                // Kartu baru langsung bisa dipakai; penonaktifan menyusul lewat
                // `updateStatus()` bila petugas memintanya.
                'status' => WalletRfidCard::STATUS_ACTIVE,
                'assigned_at' => now(),
                'issuing_batch' => $this->blankToNull($data['issuing_batch'] ?? null),
                'notes' => $this->blankToNull($data['notes'] ?? null),
                'created_by' => $actor?->id,
            ]);

            activity('wallet')
                ->event('WALLET_RFID_ASSIGNED')
                ->performedOn($card)
                ->causedBy($actor)
                ->withProperties(['wallet_number' => $wallet->number, 'uid' => $uid])
                ->log(sprintf('Kartu RFID %s ditautkan ke wallet %s oleh %s.', $uid, $wallet->number, (string) $actor?->name));

            return $card->loadMissing('wallet');
        });
    }

    /**
     * Ganti kartu: terbitkan UID baru pada wallet yang sama, matikan kartu lama.
     *
     * Wallet dan saldo tidak bergerak, dan tidak ada wallet kedua yang lahir —
     * inilah isi paragraf "mengganti RFID tidak boleh membuat wallet baru" (§6).
     *
     * @param  array{uid: string, old_status?: string|null, notes?: string|null}  $data
     * @return array{0: WalletRfidCard, 1: WalletRfidCard} kartu baru lalu kartu lama
     */
    public function replace(WalletRfidCard $card, array $data, ?User $actor = null): array
    {
        if (in_array($card->status, [WalletRfidCard::STATUS_REPLACED, WalletRfidCard::STATUS_LOST], true)) {
            throw ValidationException::withMessages([
                'card' => 'Kartu '.$card->uid.' sudah pernah dikeluarkan dari pelayanan, jadi tidak dapat diganti lagi. '
                    .'Terbitkan kartu baru pada wallet tersebut bila diperlukan.',
            ]);
        }

        if ($card->wallet === null || $card->wallet->status === Wallet::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'card' => 'Wallet kartu ini sudah ditutup sehingga kartu pengganti tidak dapat diterbitkan.',
            ]);
        }

        $uid = self::normalizeUid((string) $data['uid']);
        $oldStatus = $this->resolveOldStatus($data['old_status'] ?? null);
        $note = $this->blankToNull($data['notes'] ?? null);
        $walletId = (int) $card->wallet_id;

        $replacement = DB::transaction(function () use ($card, $uid, $walletId, $oldStatus, $note, $data, $actor): array {
            $this->assertUidAvailable($uid);

            $new = WalletRfidCard::create([
                'uid' => $uid,
                'serial_number' => $this->blankToNull($data['serial_number'] ?? null),
                'wallet_id' => $walletId,
                'status' => WalletRfidCard::STATUS_ACTIVE,
                'assigned_at' => now(),
                'issuing_batch' => $this->blankToNull($data['issuing_batch'] ?? null),
                'notes' => $note,
                'created_by' => $actor?->id,
            ]);

            $retired = $this->blankToNull($card->notes);
            $card->forceFill([
                'status' => $oldStatus,
                'replaced_by_card_id' => $new->id,
                'deactivated_at' => now(),
                'notes' => $retired === null
                    ? 'Kartu diganti oleh '.$uid
                    : $retired.' — kartu diganti oleh '.$uid,
            ])->save();

            activity('wallet')
                ->event('WALLET_RFID_REPLACED')
                ->performedOn($card)
                ->causedBy($actor)
                ->withProperties([
                    'old_uid' => $card->uid,
                    'new_uid' => $new->uid,
                    'wallet_id' => $walletId,
                    'old_card_status' => $oldStatus,
                    'reason' => $note,
                ])
                ->log(sprintf(
                    'Kartu RFID %s diganti %s pada wallet %s oleh %s.',
                    $card->uid,
                    $new->uid,
                    (string) $card->wallet?->number,
                    (string) $actor?->name,
                ));

            return [
                $new->loadMissing(['wallet', 'createdBy']),
                $card->refresh()->loadMissing(['wallet', 'replacedBy']),
            ];
        });

        return $replacement;
    }

    /**
     * Aktifkan / nonaktifkan / blokir / nyatakan hilang.
     *
     * `replaced` sengaja tidak dapat diminta dari luar: status itu akibat dari
     * `replace()`, bukan tombol.
     */
    public function updateStatus(WalletRfidCard $card, string $status, ?string $note = null, ?User $actor = null): WalletRfidCard
    {
        if (! in_array($status, self::SETTABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => in_array($status, WalletRfidCard::STATUSES, true)
                    ? 'Status '.$status.' ditetapkan otomatis saat kartu diganti, tidak dapat diubah manual.'
                    : 'Status kartu tidak dikenal. Pilihan: '.implode(', ', self::SETTABLE_STATUSES).'.',
            ]);
        }

        if ($card->status === $status) {
            throw ValidationException::withMessages([
                'status' => 'Kartu '.$card->uid.' sudah berstatus '.$status.'.',
            ]);
        }

        if ($status === WalletRfidCard::STATUS_ACTIVE && $card->wallet?->status === Wallet::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'status' => 'Kartu pada wallet yang sudah ditutup tidak dapat diaktifkan kembali.',
            ]);
        }

        $previous = (string) $card->status;
        $note = $this->blankToNull($note);

        $card->forceFill([
            'status' => $status,
            'deactivated_at' => $status === WalletRfidCard::STATUS_ACTIVE ? null : now(),
            'notes' => $note ?? $card->notes,
        ])->save();

        activity('wallet')
            ->event('WALLET_RFID_STATUS_CHANGED')
            ->performedOn($card)
            ->causedBy($actor)
            ->withProperties(['previous_status' => $previous, 'status' => $status, 'note' => $note])
            ->log(sprintf(
                'Status kartu RFID %s diubah dari %s menjadi %s oleh %s%s.',
                $card->uid,
                $previous,
                $status,
                (string) $actor?->name,
                $note === null ? '' : " ({$note})",
            ));

        return $card->refresh()->loadMissing('wallet');
    }

    /** @param  array<string, mixed>  $data */
    public function setStatus(WalletRfidCard $card, array $data, ?User $actor = null): WalletRfidCard
    {
        return $this->updateStatus(
            $card,
            (string) $data['status'],
            $this->blankToNull($data['note'] ?? $data['reason'] ?? null),
            $actor,
        );
    }

    /**
     * Blokir seluruh kartu aktif satu wallet (§21).
     *
     * Dipanggil `WalletService::block()` saat petugas memilih ikut memblokir
     * kartunya. Saldo tidak disentuh.
     */
    public function blockAllForWallet(Wallet $wallet, ?string $reason = null, ?User $actor = null): int
    {
        return $this->retireForWallet($wallet, WalletRfidCard::STATUS_BLOCKED, $reason, $actor);
    }

    /** Nonaktifkan semua kartu (dipakai saat wallet ditutup permanen). */
    public function deactivateAllForWallet(Wallet $wallet, ?string $reason = null, ?User $actor = null): int
    {
        return $this->retireForWallet($wallet, WalletRfidCard::STATUS_INACTIVE, $reason, $actor);
    }

    /**
     * Daftar kartu lintas wallet untuk petugas (§7).
     *
     * @return Builder<WalletRfidCard>
     */
    public function query(?Request $request = null): Builder
    {
        $query = WalletRfidCard::query()
            ->with(['wallet.student', 'wallet.employee', 'wallet.guardian', 'wallet.user', 'replacedBy']);

        if ($request === null) {
            return $query->latest('id');
        }

        $search = trim((string) $request->string('search')->toString());
        $like = '%'.$search.'%';

        return $query
            ->when($request->filled('wallet_id'), fn (Builder $q) => $q->where('wallet_id', (int) $request->input('wallet_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('owner_type'), fn (Builder $q) => $q->whereHas(
                'wallet',
                fn (Builder $w) => $w->ofType((string) $request->input('owner_type')),
            ))
            ->when($search !== '', function (Builder $q) use ($like, $search): void {
                $q->where(function (Builder $scope) use ($like, $search): void {
                    $scope->where('uid', 'like', $like)
                        ->orWhere('serial_number', 'like', $like)
                        ->orWhere('issuing_batch', 'like', $like)
                        ->orWhereHas('wallet', function (Builder $w) use ($like, $search): void {
                            $w->where('number', 'like', $like);

                            if (ctype_digit($search)) {
                                $w->orWhere('student_id', (int) $search)
                                    ->orWhere('employee_id', (int) $search)
                                    ->orWhere('guardian_id', (int) $search);
                            }
                        });
                });
            })
            ->latest('id');
    }

    /**
     * Kartu milik satu wallet, terbaru lebih dulu.
     *
     * @return Collection<int, WalletRfidCard>
     */
    public function forWallet(Wallet $wallet): Collection
    {
        return $wallet->rfidCards()->with('replacedBy')->get();
    }

    // =====================================================================
    // INTERNAL
    // =====================================================================

    private function retireForWallet(Wallet $wallet, string $status, ?string $reason, ?User $actor): int
    {
        $cards = WalletRfidCard::query()
            ->where('wallet_id', $wallet->id)
            ->where('status', WalletRfidCard::STATUS_ACTIVE)
            ->get();

        foreach ($cards as $card) {
            $this->updateStatus($card, $status, $reason, $actor);
        }

        return $cards->count();
    }

    /**
     * UID distandarkan agar "04:a1:b2" dan "04:A1:B2" tidak melahirkan dua kartu.
     *
     * Dipakai juga oleh FormRequest: pembersihan harus terjadi SEBELUM aturan
     * `unique` diperiksa, bila tidak nilai yang sama datang dalam dua bentuk dan
     * meledak di constraint database alih-alih mengirim 422 ke petugas.
     */
    public static function normalizeUid(string $uid): string
    {
        return strtoupper(trim(preg_replace('/\s+/', '', $uid) ?? $uid));
    }

    /** UID unik global — bukan per wallet (§6). */
    private function assertUidAvailable(string $uid, ?int $ignoreCardId = null): void
    {
        if (WalletRfidCard::uidInUse($uid, $ignoreCardId)) {
            $holder = WalletRfidCard::query()->where('uid', $uid)->first();

            throw ValidationException::withMessages([
                'uid' => 'UID '.$uid.' sudah terdaftar'
                    .($holder === null ? '' : ' pada wallet '.$holder->wallet?->number)
                    .'. Satu kartu fisik hanya boleh menunjuk satu wallet.',
            ]);
        }
    }

    private function resolveOldStatus(mixed $requested): string
    {
        $status = $this->blankToNull($requested) === null
            ? WalletRfidCard::STATUS_REPLACED
            : (string) $requested;

        $allowed = [WalletRfidCard::STATUS_REPLACED, WalletRfidCard::STATUS_LOST, WalletRfidCard::STATUS_BLOCKED];

        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages([
                'old_status' => 'Status kartu lama setelah penggantian harus salah satu dari: '.implode(', ', $allowed).'.',
            ]);
        }

        return $status;
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
