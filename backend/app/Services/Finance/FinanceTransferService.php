<?php

namespace App\Services\Finance;

use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceTransfer;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transfer internal antar kas/bank (§42, §43).
 *
 * Alur: draft → submitted → approved → completed (cabang: rejected, cancelled).
 * Saldo baru bergerak saat `complete()`: engine memposting Dr akun tujuan / Cr akun sumber
 * dan menggerakkan kedua saldo konkret atomik — total aset tidak berubah.
 * Sumber dan tujuan tidak boleh akun yang sama, dan saldo sumber harus cukup
 * (engine menolak saldo negatif). Pengaju tidak boleh menyetujui sendiri (§3).
 *
 * Dua hal yang membuat operasi ini aman diulang (§3, §16):
 *
 * **Idempotensi.** `idempotency_key` dari pemanggil (mis. nomor slip bendahara)
 * unik per pembuat: POST yang diulang karena retry jaringan atau klik ganda
 * mengembalikan transfer yang sudah ada, sehingga tidak pernah lahir dua jurnal
 * Dr-Cr untuk satu perpindahan uang. `complete()` juga menutup diri bila jurnal
 * sudah tercatat.
 *
 * **Matriks approval nominal.** Di atas `transfer_approval_threshold` (konfigurasi,
 * bukan angka di dalam kode) satu penyetuju tidak cukup: transfer menuntut
 * penyetuju KEDUA yang bukan pengaju dan bukan penyetuju pertama, dan uang tidak
 * berpindah sebelum itu tercatat.
 *
 * Sisi bank (bila ada) dicatat sebagai baris `finance_bank_transactions` bertipe
 * transfer yang menaut ke transfer ini, untuk keperluan rekonsiliasi (§44).
 */
class FinanceTransferService
{
    public function __construct(
        private readonly FinanceNumberingService $numbering,
        private readonly FinancialTransactionEngine $ledger,
        private readonly FinanceBankTransactionService $bankTransactions,
        private readonly FinanceSettingsService $settings,
    ) {}

    /**
     * Buat transfer (§42). `submit=true` langsung mengajukan.
     *
     * @param  array{type: string, source_type: string, source_id: int, destination_type: string,
     *               destination_id: int, amount: float, transfer_date?: Carbon|string|null,
     *               reason?: string|null, submit?: bool, idempotency_key?: string|null}  $data
     */
    public function create(array $data, ?User $actor = null): FinanceTransfer
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal transfer harus lebih dari nol.']);
        }

        $sourceType = (string) ($data['source_type'] ?? '');
        $destType = (string) ($data['destination_type'] ?? '');

        $this->assertSide($sourceType, (int) ($data['source_id'] ?? 0), 'source');
        $this->assertSide($destType, (int) ($data['destination_id'] ?? 0), 'destination');

        if ($sourceType === $destType && (int) $data['source_id'] === (int) $data['destination_id']) {
            throw ValidationException::withMessages(['destination_id' => 'Akun sumber dan tujuan transfer tidak boleh sama.']);
        }

        $idempotencyKey = $this->nullableText($data['idempotency_key'] ?? null);
        $replay = $idempotencyKey === null ? null : $this->findByIdempotencyKey($actor?->id, $idempotencyKey);

        // Permintaan ulang tidak menciptakan transfer kedua dan tidak mengubah
        // yang sudah ada: apa adanya transfer itu hari ini, itu jawabannya.
        if ($replay !== null) {
            return $replay;
        }

        try {
            return DB::transaction(function () use ($data, $amount, $sourceType, $destType, $idempotencyKey, $actor): FinanceTransfer {
                $transfer = FinanceTransfer::create([
                    'transfer_number' => $this->numbering->next(FinanceNumberingService::CODE_TRANSFER),
                    'type' => (string) ($data['type'] ?? FinanceTransfer::TYPE_BANK),
                    'idempotency_key' => $idempotencyKey,
                    'source_type' => $sourceType,
                    'source_id' => (int) $data['source_id'],
                    'destination_type' => $destType,
                    'destination_id' => (int) $data['destination_id'],
                    'amount' => $amount,
                    'transfer_date' => $data['transfer_date'] ?? Carbon::now()->toDateString(),
                    'reason' => $data['reason'] ?? null,
                    'status' => FinanceTransfer::STATUS_DRAFT,
                    'requires_secondary_approval' => $this->requiresSecondaryApproval($amount),
                    'created_by' => $actor?->id,
                ]);

                // Submit ikut transaksi ini: transfer yang lahir dari permintaan
                // yang sama tidak pernah tertinggal dalam keadaan draft.
                return ! empty($data['submit']) ? $this->submit($transfer, $actor) : $transfer;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Dua permintaan paralel dengan kunci yang sama: yang kalah membaca
            // hasil yang menang, bukan menghasilkan transfer kedua.
            $concurrent = $idempotencyKey === null
                ? null
                : $this->findByIdempotencyKey($actor?->id, $idempotencyKey);

            if ($concurrent === null) {
                throw $exception;
            }

            return $concurrent;
        }
    }

    /** Ambang matriks approval §16 — nominalnya dari konfigurasi, bukan kode. */
    public function requiresSecondaryApproval(float $amount): bool
    {
        return $amount > (float) $this->settings->get('transfer_approval_threshold', 10000000);
    }

    public function submit(FinanceTransfer $transfer, ?User $actor = null): FinanceTransfer
    {
        return DB::transaction(function () use ($transfer): FinanceTransfer {
            $fresh = $this->lock($transfer);

            FinanceStatusMachine::assert($fresh, FinanceTransfer::STATUS_SUBMITTED);

            $fresh->status = FinanceTransfer::STATUS_SUBMITTED;
            $fresh->save();

            return $fresh;
        });
    }

    public function approve(FinanceTransfer $transfer, ?User $actor = null): FinanceTransfer
    {
        return DB::transaction(function () use ($transfer, $actor): FinanceTransfer {
            $fresh = $this->lock($transfer);

            FinanceStatusMachine::assert($fresh, FinanceTransfer::STATUS_APPROVED);

            if ($actor !== null && (int) $fresh->created_by === (int) $actor->id) {
                throw ValidationException::withMessages(['approved_by' => 'Pembuat transfer tidak boleh menyetujui transfernya sendiri.']);
            }

            $fresh->status = FinanceTransfer::STATUS_APPROVED;
            $fresh->approved_by = $actor?->id;
            $fresh->approved_at = Carbon::now();
            $fresh->reject_reason = null;
            $fresh->save();

            return $fresh;
        });
    }

    /**
     * Persetujuan KEDUA untuk transfer di atas ambang nominal (§16). Hanya sah
     * pada transfer yang memang ditandai butuh persetujuan kedua saat diajukan,
     * dan oleh orang ketiga — bukan pengaju dan bukan penyetuju pertama (§15).
     */
    public function approveSecondary(FinanceTransfer $transfer, ?User $actor = null): FinanceTransfer
    {
        return DB::transaction(function () use ($transfer, $actor): FinanceTransfer {
            $fresh = $this->lock($transfer);

            if ($fresh->status !== FinanceTransfer::STATUS_APPROVED) {
                throw ValidationException::withMessages(['status' => "Hanya transfer berstatus disetujui yang bisa mendapat persetujuan kedua (status: {$fresh->status})."]);
            }

            if (! $fresh->requires_secondary_approval) {
                throw ValidationException::withMessages(['requires_secondary_approval' => 'Transfer ini berada di bawah ambang persetujuan kedua, satu penyetuju sudah cukup.']);
            }

            if ($fresh->secondary_approved_by !== null) {
                // Klik ganda dari penyetuju yang sama bukan persetujuan baru.
                if ($actor !== null && (int) $fresh->secondary_approved_by === (int) $actor->id) {
                    return $fresh;
                }

                throw ValidationException::withMessages(['secondary_approved_by' => 'Persetujuan kedua sudah dicatat oleh penyetuju lain.']);
            }

            if ($actor !== null && ((int) $fresh->created_by === (int) $actor->id || (int) $fresh->approved_by === (int) $actor->id)) {
                throw ValidationException::withMessages(['secondary_approved_by' => 'Penyetuju kedua harus orang lain, bukan pengaju atau penyetuju pertama.']);
            }

            $fresh->secondary_approved_by = $actor?->id;
            $fresh->secondary_approved_at = Carbon::now();
            $fresh->save();

            return $fresh;
        });
    }

    public function reject(FinanceTransfer $transfer, string $reason, ?User $actor = null): FinanceTransfer
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reject_reason' => 'Alasan penolakan wajib diisi.']);
        }

        return DB::transaction(function () use ($transfer, $reason, $actor): FinanceTransfer {
            $fresh = $this->lock($transfer);

            FinanceStatusMachine::assert($fresh, FinanceTransfer::STATUS_REJECTED);

            $fresh->status = FinanceTransfer::STATUS_REJECTED;
            $fresh->reject_reason = $reason;
            $fresh->approved_by = $actor?->id;
            $fresh->approved_at = Carbon::now();
            $fresh->save();

            return $fresh;
        });
    }

    /**
     * Batalkan transfer yang belum dieksekusi (§3 CANCELLED) — misalnya setoran
     * batal sebelum sempat diproses. Transfer yang sudah punya jurnal tidak bisa
     * dibatalkan: yang bergerak harus dibalik lewat pembalikan jurnal, bukan
     * dengan mengubah status (§51).
     */
    public function cancel(FinanceTransfer $transfer, string $reason, ?User $actor = null): FinanceTransfer
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['cancel_reason' => 'Alasan pembatalan wajib diisi.']);
        }

        return DB::transaction(function () use ($transfer, $reason, $actor): FinanceTransfer {
            $fresh = $this->lock($transfer);

            if ($fresh->finance_transaction_id !== null || $fresh->status === FinanceTransfer::STATUS_COMPLETED) {
                throw ValidationException::withMessages(['status' => 'Transfer yang sudah berjalan tidak dapat dibatalkan; balikkan jurnalnya lewat pembalikan jurnal.']);
            }

            FinanceStatusMachine::assert($fresh, FinanceTransfer::STATUS_CANCELLED);

            $fresh->status = FinanceTransfer::STATUS_CANCELLED;
            $fresh->cancel_reason = $reason;
            $fresh->cancelled_by = $actor?->id;
            $fresh->cancelled_at = Carbon::now();
            $fresh->save();

            return $fresh;
        });
    }

    /**
     * Eksekusi transfer yang sudah disetujui (§43): posting jurnal Dr tujuan /
     * Cr sumber, gerakkan kedua saldo, catat kaki bank untuk rekonsiliasi.
     */
    public function complete(FinanceTransfer $transfer, ?User $actor = null): FinanceTransfer
    {
        return DB::transaction(function () use ($transfer, $actor): FinanceTransfer {
            $fresh = $this->lock($transfer);

            FinanceStatusMachine::assert($fresh, FinanceTransfer::STATUS_COMPLETED);

            // Uang hanya boleh berpindah sekali per transfer: kalau jurnalnya
            // sudah ada, permintaan kedua ditolak walau status masih approved.
            if ($fresh->finance_transaction_id !== null) {
                throw ValidationException::withMessages(['finance_transaction_id' => 'Transfer ini sudah memiliki jurnal dan tidak dapat diproses dua kali.']);
            }

            if ($fresh->awaitsSecondaryApproval()) {
                $threshold = (float) $this->settings->get('transfer_approval_threshold', 10000000);

                throw ValidationException::withMessages([
                    'secondary_approved_by' => 'Nominal transfer di atas ambang persetujuan ('.number_format($threshold, 0, ',', '.').'); penyelesaiannya butuh penyetuju kedua.',
                ]);
            }

            $source = $this->resolveAccount($fresh->source_type, (int) $fresh->source_id);
            $destination = $this->resolveAccount($fresh->destination_type, (int) $fresh->destination_id);
            $amount = round((float) $fresh->amount, 2);

            $fresh->status = FinanceTransfer::STATUS_COMPLETED;
            $fresh->save();

            $journal = $this->ledger->transfer($source, $destination, $amount, "Transfer {$fresh->transfer_number}", [
                'date' => $fresh->transfer_date,
                'reference_type' => FinanceTransfer::class,
                'reference_id' => $fresh->id,
                'created_by' => $actor?->id,
            ]);

            $fresh->finance_transaction_id = $journal->id;
            $fresh->completed_at = Carbon::now();
            $fresh->save();

            // Kaki bank dicatat untuk rekonsiliasi (§44); kaki kas tidak perlu.
            if ($source instanceof FinanceBankAccount) {
                $this->bankTransactions->recordTransferLeg($source, -$amount, $fresh->id, 'out', $actor);
            }

            if ($destination instanceof FinanceBankAccount) {
                $this->bankTransactions->recordTransferLeg($destination, $amount, $fresh->id, 'in', $actor);
            }

            return $fresh->fresh();
        });
    }

    // ==================== Internal ====================

    protected function nullableText(mixed $value, int $max = 100): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Kunci idempotensi terikat pada pembuatnya, termasuk saat pembuatnya kosong. */
    protected function findByIdempotencyKey(?int $actorId, string $key): ?FinanceTransfer
    {
        return FinanceTransfer::query()
            ->when($actorId === null,
                fn ($query) => $query->whereNull('created_by'),
                fn ($query) => $query->where('created_by', $actorId))
            ->where('idempotency_key', $key)
            ->first();
    }

    protected function assertSide(string $type, int $id, string $field): void
    {
        if (! in_array($type, [FinanceTransfer::SIDE_BANK, FinanceTransfer::SIDE_CASH], true)) {
            throw ValidationException::withMessages([$field.'_type' => 'Tipe akun harus bank_account atau cash_account.']);
        }

        if ($this->findAccount($type, $id) === null) {
            throw ValidationException::withMessages([$field.'_id' => 'Akun '.$field.' tidak ditemukan.']);
        }
    }

    protected function resolveAccount(string $type, int $id): FinanceCashAccount|FinanceBankAccount
    {
        $account = $this->findAccount($type, $id);

        if ($account === null) {
            throw ValidationException::withMessages(['source_id' => 'Akun transfer tidak ditemukan.']);
        }

        return $account;
    }

    protected function findAccount(string $type, int $id): FinanceCashAccount|FinanceBankAccount|null
    {
        return $type === FinanceTransfer::SIDE_BANK
            ? FinanceBankAccount::query()->whereKey($id)->first()
            : FinanceCashAccount::query()->whereKey($id)->first();
    }

    protected function lock(FinanceTransfer $transfer): FinanceTransfer
    {
        return FinanceTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
    }
}
