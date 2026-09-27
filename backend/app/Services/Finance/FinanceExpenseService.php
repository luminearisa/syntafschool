<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceExpense;
use App\Models\FinanceExpenseRequest;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengeluaran (§30–§33): pengajuan → approval bertingkat → pembayaran.
 *
 * Pemisahan pencatat vs penyetuju (§3) ditegakkan lewat permission di route dan
 * guard "pengaju tidak boleh menyetujui/membayar pengajuannya sendiri" di sini.
 * Jumlah langkah approval diturunkan dari Settings (§32): nominal di atas
 * `expense_approval_threshold` butuh `expense_approval_steps` persetujuan.
 *
 * Uang benar-benar keluar hanya saat `pay()`: jurnal diposting lewat engine
 * (Dr akun beban, Cr kas/bank — saldo konkret berkurang atomik), realisasi
 * anggaran (`used_amount`) dinaikkan (§8), dan satu baris `FinanceExpense`
 * terealisasi dibuat sebagai jejak. Request yang sudah dibayar tidak bisa
 * diubah/dibatalkan lagi (§51 — koreksi lewat reversal, bukan edit).
 *
 * Budget control (§10) dijaga di satu titik: `submit()`. Pembuatan draft tidak
 * memeriksa anggaran dan tidak memakan komitmen — komitmen baru lahir saat
 * pengajuan masuk alur persetujuan, dan Actual tetap hanya jurnal posted (§9).
 */
class FinanceExpenseService
{
    public function __construct(
        private readonly FinanceNumberingService $numbering,
        private readonly FinancialTransactionEngine $ledger,
        private readonly FinanceSettingsService $settings,
        private readonly FinanceBudgetService $budgets,
    ) {}

    /**
     * Buat pengajuan pengeluaran (§30). Status awal `draft` kecuali pemanggil
     * minta langsung diajukan (`submit=true`).
     *
     * @param  array{cost_center_id: int, account_id: int, amount: float, description: string,
     *               expense_date?: Carbon|string|null, due_date?: Carbon|string|null,
     *               vendor_id?: int|null, payee_name?: string|null, attachment_path?: string|null,
     *               submit?: bool}  $data
     */
    public function createRequest(array $data, ?User $actor = null): FinanceExpenseRequest
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal pengeluaran harus lebih dari nol.']);
        }

        $this->assertExpenseAccount((int) ($data['account_id'] ?? 0));

        $request = FinanceExpenseRequest::create([
            'request_number' => $this->numbering->next(FinanceNumberingService::CODE_EXPENSE_REQUEST),
            'requester_id' => $actor?->id,
            'cost_center_id' => (int) $data['cost_center_id'],
            'account_id' => (int) $data['account_id'],
            'vendor_id' => $data['vendor_id'] ?? null,
            'payee_name' => $data['payee_name'] ?? null,
            'amount' => $amount,
            'description' => (string) $data['description'],
            'expense_date' => $data['expense_date'] ?? Carbon::now()->toDateString(),
            'due_date' => $data['due_date'] ?? null,
            'attachment_path' => $data['attachment_path'] ?? null,
            'status' => FinanceExpenseRequest::STATUS_DRAFT,
            'required_approval_levels' => $this->requiredLevels($amount),
        ]);

        if (! empty($data['submit'])) {
            return $this->submit($request, $actor);
        }

        return $request;
    }

    /** Ajukan draft/ditolak untuk disetujui (draft|rejected → submitted). */
    public function submit(FinanceExpenseRequest $request, ?User $actor = null): FinanceExpenseRequest
    {
        return DB::transaction(function () use ($request): FinanceExpenseRequest {
            $fresh = $this->lock($request);

            FinanceStatusMachine::assert($fresh, FinanceExpenseRequest::STATUS_SUBMITTED);

            // Jumlah langkah bisa berubah bila nominal diedit sejak dibuat.
            $fresh->required_approval_levels = $this->requiredLevels((float) $fresh->amount);
            $fresh->current_approval_level = 0;
            $fresh->status = FinanceExpenseRequest::STATUS_SUBMITTED;
            $fresh->reject_reason = null;
            $fresh->rejected_by = null;

            // "Check Budget → Available?" (§10) sebelum pengajuan ini menjadi
            // komitmen: verdict-nya dibekukan di barisnya, dan kebijakan `block`
            // membuat transaksi ini rollback sehingga status tetap draft.
            $verdict = $this->budgets->applyControl(
                (int) $fresh->account_id,
                (int) $fresh->cost_center_id,
                (float) $fresh->amount,
                $fresh->expense_date,
            );

            $fresh->budget_control = $verdict['control'];
            $fresh->budget_shortfall = $verdict['shortfall'];
            $fresh->budget_override_by = null;
            $fresh->budget_override_at = null;
            $fresh->save();

            return $fresh;
        });
    }

    /**
     * Setujui satu tingkat (§32). Bila belum mencapai `required_approval_levels`
     * status jadi `under_review`; bila sudah, jadi `approved`. Pengaju tidak
     * boleh menyetujui pengajuannya sendiri (§3).
     *
     * Pengajuan yang melebihi anggaran tidak boleh selesai di tangan atasan
     * pengaju saja (§10 `ALLOW_WITH_APPROVAL`): tingkat terakhirnya harus
     * ditutup pemegang hak anggaran, dan tanda tangan itu dicatat sebagai
     * override supaya kelebihan anggaran punya penanggung jawab.
     */
    public function approve(FinanceExpenseRequest $request, ?User $actor = null): FinanceExpenseRequest
    {
        return DB::transaction(function () use ($request, $actor): FinanceExpenseRequest {
            $fresh = $this->lock($request);

            // Persetujuan bertingkat (§16) bisa menahan dokumen pada
            // under_review, jadi yang diuji adalah kelayakan masuk jalur
            // approval — bukan satu status tujuan.
            if (! FinanceStatusMachine::can($fresh, FinanceExpenseRequest::STATUS_APPROVED)) {
                throw ValidationException::withMessages([
                    'status' => "Pengajuan berstatus {$fresh->status} tidak berada pada jalur approval.",
                ]);
            }

            $this->assertNotRequester($fresh, $actor, 'approved_by');

            $required = max(1, (int) $fresh->required_approval_levels);
            $level = (int) $fresh->current_approval_level + 1;
            $awaitsBudgetAuthority = $this->budgets->awaitsBudgetAuthority($fresh);

            if ($awaitsBudgetAuthority && $level >= $required) {
                $this->assertBudgetAuthority($actor);
            }

            $fresh->current_approval_level = $level;
            $fresh->approved_by = $actor?->id;

            if ($level >= $required) {
                $fresh->status = FinanceExpenseRequest::STATUS_APPROVED;
                $fresh->approved_at = Carbon::now();

                if ($awaitsBudgetAuthority) {
                    $fresh->budget_override_by = $actor?->id;
                    $fresh->budget_override_at = Carbon::now();
                }
            } else {
                $fresh->status = FinanceExpenseRequest::STATUS_UNDER_REVIEW;
            }

            $fresh->save();

            return $fresh;
        });
    }

    /** Tolak pengajuan yang menunggu approval, wajib disertai alasan. */
    public function reject(FinanceExpenseRequest $request, string $reason, ?User $actor = null): FinanceExpenseRequest
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reject_reason' => 'Alasan penolakan wajib diisi.']);
        }

        return DB::transaction(function () use ($request, $reason, $actor): FinanceExpenseRequest {
            $fresh = $this->lock($request);

            FinanceStatusMachine::assert($fresh, FinanceExpenseRequest::STATUS_REJECTED);

            $fresh->status = FinanceExpenseRequest::STATUS_REJECTED;
            $fresh->reject_reason = $reason;
            $fresh->rejected_by = $actor?->id;
            $fresh->save();

            return $fresh;
        });
    }

    /** Batalkan pengajuan yang belum dibayar (draft|submitted|under_review|approved). */
    public function cancel(FinanceExpenseRequest $request, ?User $actor = null): FinanceExpenseRequest
    {
        return DB::transaction(function () use ($request): FinanceExpenseRequest {
            $fresh = $this->lock($request);

            // §51: yang sudah dibayar/diproses tidak dibatalkan — dibalikkan.
            FinanceStatusMachine::assert($fresh, FinanceExpenseRequest::STATUS_CANCELLED);

            $fresh->status = FinanceExpenseRequest::STATUS_CANCELLED;
            $fresh->save();

            return $fresh;
        });
    }

    /**
     * Bayar pengajuan yang sudah `approved` (§30, §31): posting jurnal keluar
     * (Dr akun beban, Cr kas/bank), buat baris expense terealisasi, naikkan
     * realisasi anggaran, dan tandai request `paid`.
     *
     * @param  array{cash_account_id?: int|null, bank_account_id?: int|null,
     *               expense_date?: Carbon|string|null, notes?: string|null}  $data
     */
    public function pay(FinanceExpenseRequest $request, array $data, ?User $actor = null): FinanceExpense
    {
        return DB::transaction(function () use ($request, $data, $actor): FinanceExpense {
            $fresh = $this->lock($request);

            FinanceStatusMachine::assert($fresh, FinanceExpenseRequest::STATUS_PROCESSING);

            $this->assertNotRequester($fresh, $actor, 'paid_by');

            $amount = round((float) $fresh->amount, 2);

            $cashId = $data['cash_account_id'] ?? $fresh->cash_account_id;
            $bankId = $data['bank_account_id'] ?? $fresh->bank_account_id;
            $account = $this->settlementAccount($cashId, $bankId);

            if ($account === null) {
                throw ValidationException::withMessages([
                    'cash_account_id' => 'Tentukan akun kas atau bank sumber dana pengeluaran.',
                ]);
            }

            $fresh->status = FinanceExpenseRequest::STATUS_PROCESSING;
            $fresh->save();

            $journal = $this->ledger->cashDisbursement(
                $account,
                (int) $fresh->account_id,
                $amount,
                "Pengeluaran {$fresh->request_number}: {$fresh->description}",
                [
                    'type' => FinancialTransaction::TYPE_EXPENSE,
                    'date' => $data['expense_date'] ?? $fresh->expense_date,
                    'reference_type' => FinanceExpenseRequest::class,
                    'reference_id' => $fresh->id,
                    'cost_center_id' => $fresh->cost_center_id,
                    'created_by' => $actor?->id,
                ],
            );

            $expense = FinanceExpense::create([
                'expense_number' => $this->numbering->next(FinanceNumberingService::CODE_EXPENSE),
                'expense_request_id' => $fresh->id,
                'cost_center_id' => $fresh->cost_center_id,
                'account_id' => $fresh->account_id,
                'vendor_id' => $fresh->vendor_id,
                'amount' => $amount,
                'description' => $fresh->description,
                'expense_date' => $data['expense_date'] ?? $fresh->expense_date,
                'finance_transaction_id' => $journal->id,
                'status' => FinanceExpense::STATUS_PAID,
                'created_by' => $actor?->id,
            ]);

            FinanceStatusMachine::assert($fresh, FinanceExpenseRequest::STATUS_PAID);
            $fresh->status = FinanceExpenseRequest::STATUS_PAID;
            $fresh->cash_account_id = $cashId;
            $fresh->bank_account_id = $bankId;
            $fresh->finance_transaction_id = $journal->id;
            $fresh->paid_at = Carbon::now();
            $fresh->paid_by = $actor?->id;
            $fresh->save();

            $this->budgets->addUsageFor((int) $fresh->cost_center_id, (int) $fresh->account_id, $amount, $fresh->expense_date);

            return $expense->load('expenseRequest');
        });
    }

    // ==================== Internal ====================

    /** Tingkat approval diperlukan: di atas ambang → `expense_approval_steps`, selain itu 1. */
    protected function requiredLevels(float $amount): int
    {
        $threshold = (float) $this->settings->get('expense_approval_threshold', 0);
        $steps = (int) $this->settings->get('expense_approval_steps', 1);

        if ($threshold > 0 && $amount > $threshold) {
            return max(1, $steps);
        }

        return 1;
    }

    protected function assertNotRequester(FinanceExpenseRequest $request, ?User $actor, string $field): void
    {
        if ($actor !== null && (int) $request->requester_id === (int) $actor->id) {
            throw ValidationException::withMessages([
                $field => 'Pengaju tidak boleh menyetujui atau membayar pengajuannya sendiri.',
            ]);
        }
    }

    /**
     * §10 `ALLOW_WITH_APPROVAL`: kelebihan anggaran hanya boleh ditutup oleh
     * pemegang hak anggaran (`finance.budget.approve`) — hak yang sama untuk
     * menyetujui anggarannya sendiri. Tanpa syarat ini "butuh persetujuan"
     * berubah menjadi formalitas di tangan atasan pengaju.
     */
    protected function assertBudgetAuthority(?User $actor): void
    {
        if ($actor !== null && $actor->can('finance.budget.approve')) {
            return;
        }

        throw ValidationException::withMessages([
            'budget_override_by' => 'Pengajuan melebihi anggaran hanya bisa disetujui terakhir oleh pemegang hak anggaran.',
        ]);
    }

    /** Akun beban harus leaf, aktif, dan bertipe expense (§31, §47). */
    protected function assertExpenseAccount(int $accountId): void
    {
        $account = FinanceAccount::query()->whereKey($accountId)->first();

        if ($account === null) {
            throw ValidationException::withMessages(['account_id' => 'Akun beban tidak ditemukan.']);
        }

        if ($account->type !== FinanceAccount::TYPE_EXPENSE) {
            throw ValidationException::withMessages(['account_id' => "Akun {$account->code} bukan akun pengeluaran."]);
        }

        if (! $account->isLeaf() || ! $account->is_active) {
            throw ValidationException::withMessages(['account_id' => "Akun {$account->code} tidak bisa dipakai sebagai tujuan pengeluaran."]);
        }
    }

    protected function settlementAccount(mixed $cashId, mixed $bankId): FinanceCashAccount|FinanceBankAccount|null
    {
        if ($cashId !== null) {
            return FinanceCashAccount::query()->whereKey($cashId)->first();
        }

        if ($bankId !== null) {
            return FinanceBankAccount::query()->whereKey($bankId)->first();
        }

        return null;
    }

    protected function lock(FinanceExpenseRequest $request): FinanceExpenseRequest
    {
        return FinanceExpenseRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
    }
}
