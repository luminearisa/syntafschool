<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceAdjustment;
use App\Models\FinanceBankAccount;
use App\Models\FinanceBilling;
use App\Models\FinanceCashAccount;
use App\Models\FinanceInvoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adjustment / koreksi (§51) oleh Finance Manager + write-off piutang (§32–§34).
 *
 * Tidak pernah mengedit transaksi lama: koreksi diposting sebagai jurnal BARU
 * setelah approval. `account_id` adalah COA yang dikoreksi, `contra_account_id`
 * penyeimbangnya (mis. beban/pendapatan selisih). `direction` menentukan sisi:
 * increase → Dr account_id / Cr contra; decrease → Cr account_id / Dr contra.
 *
 * Write-off (§32–§34) memakai tabel + alur approval yang sama (`kind=write_off`
 * + `invoice_id`) — bukan entitas koreksi kedua, karena kebutuhan auditnya
 * identik: alasan wajib, approver berbeda dari peminta, jurnal baru, dokumen
 * asli tak diubah. Susunan debit/kreditnya (Beban Write-Off / Piutang) milik
 * `JournalPostingService` dan akunnya dibaca dari kebijakan (§20), bukan
 * diketik pemakai.
 *
 * Bila adjustment menyasar saldo konkret (kas/bank), pemanggil menyuplai
 * `cash_account_id`/`bank_account_id` yang COA-nya harus sama dengan `account_id`;
 * saldo konkret ikut digerakkan sejalan jurnal (tidak ada saldo berubah tanpa
 * transaction record §61).
 *
 * Alur: draft → submitted → approved → posted (atau rejected).
 */
class FinanceAdjustmentService
{
    public function __construct(
        private readonly FinanceNumberingService $numbering,
        private readonly JournalPostingService $posting,
        private readonly AccountingPolicyService $policy,
        private readonly FinanceOutstandingRecalculator $balances,
    ) {}

    /**
     * @param  array{account_id?: int|null, contra_account_id?: int|null, direction?: string,
     *               amount: float, adjustment_date?: Carbon|string|null, reason: string,
     *               cost_center_id?: int|null, cash_account_id?: int|null, bank_account_id?: int|null,
     *               kind?: string, invoice_id?: int|null, submit?: bool}  $data
     */
    public function create(array $data, ?User $actor = null): FinanceAdjustment
    {
        $kind = (string) ($data['kind'] ?? FinanceAdjustment::KIND_GENERAL);

        if (! in_array($kind, FinanceAdjustment::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Jenis adjustment tidak dikenal.']);
        }

        if ($kind === FinanceAdjustment::KIND_WRITE_OFF) {
            return $this->createWriteOff($data, $actor);
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal adjustment harus lebih dari nol.']);
        }

        $direction = (string) ($data['direction'] ?? FinanceAdjustment::DIRECTION_INCREASE);

        if (! in_array($direction, [FinanceAdjustment::DIRECTION_INCREASE, FinanceAdjustment::DIRECTION_DECREASE], true)) {
            throw ValidationException::withMessages(['direction' => 'Arah adjustment harus increase atau decrease.']);
        }

        $accountId = (int) ($data['account_id'] ?? 0);
        $contraId = (int) ($data['contra_account_id'] ?? 0);

        $this->assertPostableAccount($accountId, 'account_id');
        $this->assertPostableAccount($contraId, 'contra_account_id');

        if ($accountId === $contraId) {
            throw ValidationException::withMessages(['contra_account_id' => 'Akun yang dikoreksi dan akun lawan tidak boleh sama.']);
        }

        $cashId = $data['cash_account_id'] ?? null;
        $bankId = $data['bank_account_id'] ?? null;

        // Validasi akun konkret di sini (bukan saat post) agar kesalahan COA
        // tertangkap ketika konteks masih lengkap.
        $this->assertConcrete($cashId, $bankId, $accountId);

        $adjustment = FinanceAdjustment::create([
            'adjustment_number' => $this->numbering->next(FinanceNumberingService::CODE_ADJUSTMENT),
            'kind' => FinanceAdjustment::KIND_GENERAL,
            'account_id' => $accountId,
            'contra_account_id' => $contraId,
            'direction' => $direction,
            'amount' => $amount,
            'adjustment_date' => $data['adjustment_date'] ?? Carbon::now()->toDateString(),
            'reason' => (string) ($data['reason'] ?? ''),
            'cost_center_id' => $data['cost_center_id'] ?? null,
            'cash_account_id' => $cashId,
            'bank_account_id' => $bankId,
            'status' => FinanceAdjustment::STATUS_DRAFT,
            'requested_by' => $actor?->id,
        ]);

        if (! empty($data['submit'])) {
            return $this->submit($adjustment, $actor);
        }

        return $adjustment;
    }

    /**
     * Ajukan write-off piutang (§32–§34). Akun debit (Beban Write-Off) dan kredit
     * (Piutang) diambil dari kebijakan akuntansi, bukan dari permintaan; yang
     * boleh dipilih pemakai hanya invoice, nominal, tanggal, dan alasan.
     *
     * §33: write-off tidak menghapus tagihan — `written_off_amount` invoice
     * bertambah dan sisa tagihan mengecil, sehingga riwayat tetap terbaca.
     *
     * @param  array{invoice_id?: int|null, amount: float, reason: string,
     *               adjustment_date?: Carbon|string|null, cost_center_id?: int|null, submit?: bool}  $data
     */
    public function createWriteOff(array $data, ?User $actor = null): FinanceAdjustment
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        $reason = trim((string) ($data['reason'] ?? ''));

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal write-off harus lebih dari nol.']);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan write-off wajib diisi (§33).']);
        }

        if (empty($data['invoice_id'])) {
            throw ValidationException::withMessages(['invoice_id' => 'Write-off wajib menunjuk satu invoice.']);
        }

        // Kedua akun jurnal ditentukan kebijakan — divalidasi di sini supaya gagal
        // cepat dengan pesan yang menyebut kunci setting, bukan saat posting.
        $debitId = $this->policy->writeOffExpenseAccountId();
        $contraId = $this->policy->receivableAccountId();

        $adjustment = DB::transaction(function () use ($data, $amount, $reason, $debitId, $contraId, $actor): FinanceAdjustment {
            $invoice = FinanceInvoice::query()->whereKey($data['invoice_id'])->lockForUpdate()->first();

            if ($invoice === null) {
                throw ValidationException::withMessages(['invoice_id' => 'Invoice tidak ditemukan.']);
            }

            $remaining = round((float) $invoice->remaining_amount, 2);

            if ($invoice->status === FinanceInvoice::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['invoice_id' => 'Invoice dibatalkan tidak bisa di-write-off.']);
            }

            if ($remaining <= 0.0001) {
                throw ValidationException::withMessages(['invoice_id' => "Invoice {$invoice->invoice_number} sudah tidak punya sisa tagihan."]);
            }

            if ($amount > $remaining + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal write-off melebihi sisa tagihan Rp '.number_format($remaining, 0, ',', '.').'.',
                ]);
            }

            return FinanceAdjustment::create([
                'adjustment_number' => $this->numbering->next(FinanceNumberingService::CODE_ADJUSTMENT),
                'kind' => FinanceAdjustment::KIND_WRITE_OFF,
                'invoice_id' => $invoice->id,
                'account_id' => $debitId,
                'contra_account_id' => $contraId,
                'direction' => FinanceAdjustment::DIRECTION_INCREASE,
                'amount' => $amount,
                'adjustment_date' => $data['adjustment_date'] ?? Carbon::now()->toDateString(),
                'reason' => $reason,
                'cost_center_id' => $data['cost_center_id'] ?? $invoice->cost_center_id,
                'status' => FinanceAdjustment::STATUS_DRAFT,
                'requested_by' => $actor?->id,
            ]);
        });

        return ! empty($data['submit']) ? $this->submit($adjustment, $actor) : $adjustment;
    }

    public function submit(FinanceAdjustment $adjustment, ?User $actor = null): FinanceAdjustment
    {
        return DB::transaction(function () use ($adjustment): FinanceAdjustment {
            $fresh = $this->lock($adjustment);

            FinanceStatusMachine::assert($fresh, FinanceAdjustment::STATUS_SUBMITTED);

            $fresh->status = FinanceAdjustment::STATUS_SUBMITTED;
            $fresh->save();

            return $fresh;
        });
    }

    public function approve(FinanceAdjustment $adjustment, ?User $actor = null): FinanceAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor): FinanceAdjustment {
            $fresh = $this->lock($adjustment);

            FinanceStatusMachine::assert($fresh, FinanceAdjustment::STATUS_APPROVED);

            if ($actor !== null && (int) $fresh->requested_by === (int) $actor->id) {
                throw ValidationException::withMessages(['approved_by' => 'Peminta adjustment tidak boleh menyetujui adjustmennya sendiri.']);
            }

            $fresh->status = FinanceAdjustment::STATUS_APPROVED;
            $fresh->approved_by = $actor?->id;
            $fresh->approved_at = Carbon::now();
            $fresh->reject_reason = null;
            $fresh->save();

            return $fresh;
        });
    }

    public function reject(FinanceAdjustment $adjustment, string $reason, ?User $actor = null): FinanceAdjustment
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reject_reason' => 'Alasan penolakan wajib diisi.']);
        }

        return DB::transaction(function () use ($adjustment, $reason, $actor): FinanceAdjustment {
            $fresh = $this->lock($adjustment);

            FinanceStatusMachine::assert($fresh, FinanceAdjustment::STATUS_REJECTED);

            $fresh->status = FinanceAdjustment::STATUS_REJECTED;
            $fresh->reject_reason = $reason;
            $fresh->approved_by = $actor?->id;
            $fresh->approved_at = Carbon::now();
            $fresh->save();

            return $fresh;
        });
    }

    /**
     * Posting adjustment yang sudah disetujui (§51): jurnal baru Dr/Cr sesuai arah.
     * Kontra + akun konkret dibaca dari model yang dipersist saat create().
     *
     * Write-off piutang (§33) bercabang lebih dulu: jurnalnya disusun oleh
     * `JournalPostingService` dan sisa tagihan invoice ikut dikurangi.
     */
    public function post(FinanceAdjustment $adjustment, ?User $actor = null): FinanceAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor): FinanceAdjustment {
            $fresh = $this->lock($adjustment);

            FinanceStatusMachine::assert($fresh, FinanceAdjustment::STATUS_POSTED);

            if ($fresh->kind === FinanceAdjustment::KIND_WRITE_OFF) {
                return $this->postWriteOff($fresh, $actor);
            }

            $contraId = (int) $fresh->contra_account_id;
            $this->assertPostableAccount($contraId, 'contra_account_id');

            if ($contraId === (int) $fresh->account_id) {
                throw ValidationException::withMessages(['contra_account_id' => 'Akun yang dikoreksi dan akun lawan tidak boleh sama.']);
            }

            $amount = round((float) $fresh->amount, 2);
            $increase = $fresh->direction === FinanceAdjustment::DIRECTION_INCREASE;

            $concrete = $this->resolveConcrete($fresh, (int) $fresh->account_id);

            $balances = $concrete !== null
                ? [['account' => $concrete, 'delta' => $increase ? $amount : -$amount]]
                : [];

            $journal = $this->posting->correction($fresh, $actor, $balances);

            $fresh->status = FinanceAdjustment::STATUS_POSTED;
            $fresh->finance_transaction_id = $journal->id;
            $fresh->save();

            return $fresh->fresh();
        });
    }

    // ==================== Internal ====================

    /**
     * Tulis write-off yang sudah disetujui ke buku besar + kurangi sisa tagihan
     * invoice (§33). Jurnal dan pembaruan invoice satu transaksi: tidak pernah
     * ada piutang terhapus tanpa jurnal, atau sebaliknya.
     */
    protected function postWriteOff(FinanceAdjustment $adjustment, ?User $actor): FinanceAdjustment
    {
        $invoice = FinanceInvoice::query()->whereKey($adjustment->invoice_id)->lockForUpdate()->first();

        if ($invoice === null) {
            throw ValidationException::withMessages(['invoice_id' => 'Invoice write-off tidak ditemukan.']);
        }

        $amount = round((float) $adjustment->amount, 2);
        $remaining = round((float) $invoice->remaining_amount, 2);

        // Sisa bisa berubah antara pengajuan dan posting (pembayaran lain masuk).
        if ($amount > $remaining + 0.0001) {
            throw ValidationException::withMessages([
                'amount' => "Sisa tagihan {$invoice->invoice_number} tinggal Rp ".number_format($remaining, 0, ',', '.').
                    '; write-off sebesar ini tidak bisa diposting.',
            ]);
        }

        $journal = $this->posting->writeOff($adjustment, $invoice, $actor);

        // Status dokumen tetap punya makna penagihan; status AR "written_off"
        // diturunkan terpisah oleh FinanceInvoice::receivableStatus() (§21).
        $this->balances->applyWriteOff($invoice, $amount);

        if ($invoice->billing_id !== null) {
            $this->applyToBilling($invoice, $amount);
        }

        $adjustment->status = FinanceAdjustment::STATUS_POSTED;
        $adjustment->finance_transaction_id = $journal->id;
        $adjustment->save();

        return $adjustment->fresh();
    }

    /**
     * Sisa tagihan billing induk ikut menyusut agar laporan penagihan tidak
     * menampilkan piutang yang sudah dihapus. `paid_amount` sengaja TIDAK
     * bergerak: write-off bukan pembayaran (§33).
     */
    protected function applyToBilling(FinanceInvoice $invoice, float $amount): void
    {
        $billing = FinanceBilling::query()->whereKey($invoice->billing_id)->lockForUpdate()->first();

        if ($billing === null) {
            return;
        }

        $this->balances->applyWriteOff($billing, $amount);
    }

    /**
     * Validasi akun konkret (kas/bank) yang saldonya akan digerakkan; COA-nya harus
     * sama dengan akun yang dikoreksi agar jurnal dan subledger konsisten.
     */
    protected function assertConcrete(mixed $cashId, mixed $bankId, int $accountId): void
    {
        if ($cashId !== null) {
            $cash = FinanceCashAccount::query()->whereKey($cashId)->first();

            if ($cash === null) {
                throw ValidationException::withMessages(['cash_account_id' => 'Akun kas tidak ditemukan.']);
            }

            if ((int) $cash->account_id !== $accountId) {
                throw ValidationException::withMessages(['cash_account_id' => 'COA akun kas tidak cocok dengan akun yang dikoreksi.']);
            }
        }

        if ($bankId !== null) {
            $bank = FinanceBankAccount::query()->whereKey($bankId)->first();

            if ($bank === null) {
                throw ValidationException::withMessages(['bank_account_id' => 'Akun bank tidak ditemukan.']);
            }

            if ((int) $bank->finance_account_id !== $accountId) {
                throw ValidationException::withMessages(['bank_account_id' => 'COA akun bank tidak cocok dengan akun yang dikoreksi.']);
            }
        }
    }

    /**
     * Ambil akun konkret tersimpan (kas didahulukan) untuk pergerakan saldo.
     */
    protected function resolveConcrete(FinanceAdjustment $adjustment, int $accountId): FinanceCashAccount|FinanceBankAccount|null
    {
        if ($adjustment->cash_account_id !== null) {
            $this->assertConcrete($adjustment->cash_account_id, null, $accountId);

            return FinanceCashAccount::query()->whereKey($adjustment->cash_account_id)->firstOrFail();
        }

        if ($adjustment->bank_account_id !== null) {
            $this->assertConcrete(null, $adjustment->bank_account_id, $accountId);

            return FinanceBankAccount::query()->whereKey($adjustment->bank_account_id)->firstOrFail();
        }

        return null;
    }

    protected function assertPostableAccount(int $accountId, string $field): void
    {
        $account = FinanceAccount::query()->whereKey($accountId)->first();

        if ($account === null) {
            throw ValidationException::withMessages([$field => 'Akun tidak ditemukan.']);
        }

        if (! $account->isLeaf() || ! $account->is_active) {
            throw ValidationException::withMessages([$field => "Akun {$account->code} tidak bisa diposting (induk/nonaktif)."]);
        }
    }

    protected function lock(FinanceAdjustment $adjustment): FinanceAdjustment
    {
        return FinanceAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
    }
}
