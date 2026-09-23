<?php

namespace App\Services\Wallet;

use App\Models\Wallet;
use App\Models\WalletReversal;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya jalan mengubah saldo wallet.
 *
 * Semua jalur — top up terverifikasi, penarikan selesai, adjustment, reversal —
 * memakai `post()` ini, sehingga `balance_before`/`balance_after` selalu merupakan
 * rantai yang terhubung dan tidak ada satu penulis saldo pun di luar ledger (§8).
 *
 * Setiap metode membuka transaksi database sendiri (`DB::transaction` bersarang
 * aman: di dalam transaksi pemanggil ia jadi savepoint). Baris wallet dikunci
 * lebih dulu, sehingga dua permintaan bersamaan tidak bisa membaca `balance`
 * yang sama lalu dua kali menambah saldo (§39).
 */
class WalletLedgerService
{
    /** Uang sampai satuan sen; nilai di bawah itu tanda pembulatan yang salah. */
    private const MINOR_UNIT = 0.01;

    public function __construct(
        private readonly WalletNumberingService $numbering,
        private readonly WalletSettingsService $settings,
    ) {
    }

    /**
     * Catat satu transaksi dan pindahkan saldo sejauh nominalnya.
     *
     * @param  array{reference?: Model|null, description?: string|null, metadata?: array,
     *               created_by?: int|null, approved_by?: int|null,
     *               source_transaction_id?: int|null}  $context
     */
    public function post(Wallet $wallet, string $type, float $amount, array $context = []): WalletTransaction
    {
        if (! in_array($type, WalletTransaction::TYPES, true)) {
            throw new \InvalidArgumentException("Tipe transaksi tidak dikenal: {$type}");
        }

        $this->assertAmount($amount);

        return DB::transaction(function () use ($wallet, $type, $amount, $context): WalletTransaction {
            $locked = $this->lockWallet((int) $wallet->id);

            $direction = in_array($type, WalletTransaction::CREDIT_TYPES, true)
                ? WalletTransaction::DIRECTION_CREDIT
                : WalletTransaction::DIRECTION_DEBIT;

            $before = round((float) $locked->balance, 2);
            $after = round($direction === WalletTransaction::DIRECTION_CREDIT
                ? $before + $amount
                : $before - $amount, 2);

            if ($after < 0 && ! $this->settings->get('negative_balance_allowed', false)) {
                throw ValidationException::withMessages([
                    'amount' => "Saldo wallet tidak mencukupi. Tersedia Rp ".number_format($before, 0, ',', '.'),
                ]);
            }

            $reference = $context['reference'] ?? null;

            $transaction = WalletTransaction::create([
                'wallet_id' => $locked->id,
                'transaction_number' => $this->numbering->next(WalletNumberingService::CODE_TRANSACTION),
                'type' => $type,
                'direction' => $direction,
                'amount' => round($amount, 2),
                'balance_before' => $before,
                'balance_after' => $after,
                'status' => WalletTransaction::STATUS_POSTED,
                'reference_type' => $reference instanceof Model ? $reference::class : null,
                'reference_id' => $reference instanceof Model ? $reference->getKey() : null,
                'source_transaction_id' => $context['source_transaction_id'] ?? null,
                'description' => $context['description'] ?? null,
                'metadata' => $context['metadata'] ?? null,
                'created_by' => $context['created_by'] ?? null,
                'approved_by' => $context['approved_by'] ?? null,
                'approved_at' => isset($context['approved_by']) ? Carbon::now() : null,
                'posted_at' => Carbon::now(),
            ]);

            $locked->balance = $after;
            $locked->save();

            // Panggilan masih memegang model lama; segarkan agar saldo berikutnya benar.
            $this->refreshFrom($wallet, $locked);

            return $transaction;
        });
    }

    /**
     * Batalkan transaksi yang salah catat dengan baris lawan.
     *
     * Baris asli tidak pernah diubah nominalnya, hanya ditandai `reversed`,
     * sehingga pembukuan masih bisa ditelusuri dua arah (§9, FLOW 8).
     *
     * @return array{0: WalletReversal, 1: WalletTransaction}
     */
    public function reverse(WalletTransaction $original, string $reason, ?int $actorId = null): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan pembatalan wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($original, $reason, $actorId): array {
            $wallet = $this->lockWallet((int) $original->wallet_id);

            $fresh = WalletTransaction::query()->whereKey($original->id)->firstOrFail();

            if ($fresh->isReversed()) {
                throw ValidationException::withMessages([
                    'transaction_id' => "Transaksi {$fresh->transaction_number} sudah pernah dibatalkan.",
                ]);
            }

            $counterpart = $this->post($wallet, WalletTransaction::TYPE_REVERSAL, (float) $fresh->amount, [
                'source_transaction_id' => $fresh->id,
                'description' => "Pembatalan {$fresh->transaction_number}: {$reason}",
                'metadata' => ['reverses' => $fresh->transaction_number, 'reason' => $reason],
                'created_by' => $actorId,
                'approved_by' => $actorId,
            ]);

            $fresh->status = WalletTransaction::STATUS_REVERSED;
            $fresh->save();

            $reversal = WalletReversal::create([
                'number' => $this->numbering->next(WalletNumberingService::CODE_REVERSAL),
                'wallet_id' => $wallet->id,
                'transaction_id' => $fresh->id,
                'reversal_transaction_id' => $counterpart->id,
                'reason' => $reason,
                'created_by' => $actorId,
            ]);

            return [$reversal, $counterpart];
        });
    }

    /**
     * Tahan sebagian saldo untuk permintaan penarikan (§17).
     *
     * Tahanan tidak menyentuh ledger — ini keadaan operasional, bukan pembukuan.
     * Saldo yang ditahan masih terlihat di `balance`, hanya tidak tersedia.
     */
    public function hold(Wallet $wallet, float $amount): Wallet
    {
        $this->assertAmount($amount);

        return DB::transaction(function () use ($wallet, $amount): Wallet {
            $locked = $this->lockWallet((int) $wallet->id);

            $available = $locked->availableBalance();

            if (round($amount - $available, 2) > self::MINOR_UNIT) {
                throw ValidationException::withMessages([
                    'amount' => 'Saldo tersedia tidak cukup. Saldo yang sedang ditahan permintaan lain tidak dapat ditarik.',
                ]);
            }

            $locked->held_balance = round((float) $locked->held_balance + $amount, 2);
            $locked->save();

            $this->refreshFrom($wallet, $locked);

            return $locked;
        });
    }

    /** Lepas tahanan bila penarikan selesai, ditolak, atau gagal (§17, §18). */
    public function releaseHold(Wallet $wallet, float $amount): Wallet
    {
        $this->assertAmount($amount);

        return DB::transaction(function () use ($wallet, $amount): Wallet {
            $locked = $this->lockWallet((int) $wallet->id);

            $held = round((float) $locked->held_balance, 2);

            // Tidak pernah boleh jadi negatif: kelebihan lepas = bug pemanggil.
            $release = min($amount, $held);

            $locked->held_balance = round($held - $release, 2);
            $locked->save();

            $this->refreshFrom($wallet, $locked);

            return $locked;
        });
    }

    /** Baris wallet terkunci — dasar seluruh perhitungan saldo. */
    public function lockWallet(int $walletId): Wallet
    {
        return Wallet::query()->whereKey($walletId)->lockForUpdate()->firstOrFail();
    }

    private function assertAmount(float $amount): void
    {
        if (! is_finite($amount) || round($amount, 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal harus lebih besar dari nol.',
            ]);
        }

        if (abs(round($amount, 2) - $amount) > self::MINOR_UNIT / 2) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal maksimum dua angka di belakang koma (sampai sen).',
            ]);
        }
    }

    private function refreshFrom(Wallet $target, Wallet $source): void
    {
        // setRawAttributes() sekaligus menyinkronkan "original" sehingga audit
        // model tidak salah melihat perubahan.
        $target->setRawAttributes($source->getAttributes(), true);
    }
}
