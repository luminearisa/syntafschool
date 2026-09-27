<?php

namespace App\Services\Finance;

use App\Models\FinanceBankAccount;
use App\Models\FinanceBilling;
use App\Models\FinanceCashAccount;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinancePaymentAllocation;
use App\Models\FinancePaymentVerification;
use App\Models\FinanceReceipt;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mesin pembayaran (§19–§25) + kuitansi + pembatalan.
 *
 * Prinsip yang dijaga:
 *  - Saldo invoice/billing TIDAK berubah dan ledger TIDAK diposting sampai
 *    payment `paid` (§21). Metode manual/gateway lahir `pending` dan baru
 *    menimbulkan uang setelah diverifikasi.
 *  - Payment `cash`/`wallet` selesai seketika: jurnal Dr Kas/Bank, Cr Piutang
 *    (1300) diposting, invoice/billing diterapkan, kuitansi terbit (§24).
 *  - Kuitansi hanya untuk payment confirmed (§24) dan TIDAK pernah dihapus —
 *    pembatalan menandai `is_cancelled` (§25).
 *  - Payment asli TIDAK dihapus (§29): pembatalan membalik jurnal (reversal)
 *    dan menarik kembali penerapan ke invoice.
 *  - Pembayaran wallet (§61) menyentuh DUA buku yang tetap terpisah: saldo
 *    wallet berkurang lewat `WalletLedgerService` (catatan wallet), dan Finance
 *    memposting jurnalnya sendiri ke akun penampung e-wallet. Finance tidak
 *    pernah mengubah saldo tanpa jurnal.
 *  - Sisa tagihan tidak dihitung di sini: mesin ini hanya mengirim selisih
 *    pembayaran ke FinanceOutstandingRecalculator (§31), satu-satunya tempat
 *    rumus outstanding hidup.
 */
class FinancePaymentService
{
    public function __construct(
        private readonly FinanceNumberingService $numbering,
        private readonly JournalPostingService $posting,
        private readonly WalletLedgerService $walletLedger,
        private readonly ReRegistrationService $reRegistration,
        private readonly FinanceSettingsService $settings,
        private readonly FinanceNotificationService $notifications,
        private readonly FinanceOutstandingRecalculator $balances,
    ) {}

    /**
     * Catat satu pembayaran (§19). Metode tunai/wallet langsung lunas; metode
     * manual & gateway lahir `pending` menunggu verifikasi (§21).
     *
     * Pembayaran dapat dialokasikan ke satu invoice (`invoice_id`/`billing_id`)
     * atau ke banyak invoice sekaligus (`allocations`, §15–§17). Baris alokasi
     * ditulis saat pencatatan dan baru memengaruhi saldo invoice ketika payment
     * `paid` (§21) — invoice tidak pernah dianggap lunas sebelum alokasi nyata.
     *
     * `idempotency_key` (§4) membuat permintaan ulang mengembalikan pembayaran
     * yang sudah ada, bukan pembayaran kedua: kunci dibandingkan per pembuat,
     * dan unik di database menahan versi paralelnya.
     *
     * @param  array{
     *     invoice_id?: int|null, billing_id?: int|null,
     *     allocations?: array<int, array{invoice_id: int, amount: float}>|null,
     *     customer_type?: string, customer_id: int, student_id?: int|null,
     *     amount: float, method: string,
     *     cash_account_id?: int|null, bank_account_id?: int|null,
     *     payment_method_id?: int|null, cost_center_id?: int|null,
     *     cashier_session_id?: int|null, wallet_id?: int|null,
     *     reference_number?: string|null, proof_path?: string|null,
     *     gateway?: string|null, notes?: string|null,
     *     paid_at?: Carbon|string|null, created_by?: int|null,
     *     idempotency_key?: string|null
     * }  $data
     */
    public function record(array $data, ?User $actor = null): FinancePayment
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal pembayaran harus lebih dari nol.']);
        }

        $method = (string) ($data['method'] ?? FinancePayment::METHOD_CASH);

        if (! in_array($method, FinancePayment::METHODS, true)) {
            throw ValidationException::withMessages(['method' => 'Metode pembayaran tidak dikenal.']);
        }

        // §4: kunci idempotensi dipasok pemanggil (nomor slip, referensi kasir).
        // Permintaan ulang tidak boleh menghasilkan pembayaran kedua — dua kali
        // mencatat slip yang sama berarti dua jurnal, dua kuitansi, dan sisa
        // tagihan yang bergerak dua kali.
        $idempotencyKey = $this->idempotencyKey($data['idempotency_key'] ?? null);
        $creatorId = $data['created_by'] ?? $actor?->id;

        if ($idempotencyKey !== null && ($replay = $this->findByIdempotencyKey($creatorId, $idempotencyKey)) !== null) {
            return $replay;
        }

        try {
            return DB::transaction(function () use ($data, $amount, $method, $actor, $idempotencyKey): FinancePayment {
                $plan = $this->planAllocations($data, $amount);

                $this->assertSettlementAccount($method, $data);

                // `paid` tidak pernah menjadi nilai awal. Pembayaran tunai/wallet
                // lahir `pending` lalu pindah oleh settle() di transaksi yang
                // sama, sehingga hanya ada satu jalur menuju lunas (§6).
                $immediate = in_array($method, [FinancePayment::METHOD_CASH, FinancePayment::METHOD_WALLET], true);

                $invoice = $plan['primaryInvoice'];

                $payment = FinancePayment::create([
                    'payment_number' => $this->numbering->next(FinanceNumberingService::CODE_PAYMENT),
                    'idempotency_key' => $idempotencyKey,
                    'invoice_id' => $invoice?->id,
                    'billing_id' => $plan['primaryBillingId'],
                    'customer_type' => $data['customer_type'] ?? ($invoice?->customer_type ?? 'student'),
                    'customer_id' => $data['customer_id'] ?? $invoice?->customer_id,
                    'student_id' => $data['student_id'] ?? $invoice?->student_id,
                    'amount' => $amount,
                    'method' => $method,
                    'payment_method_id' => $data['payment_method_id'] ?? null,
                    'status' => FinancePayment::STATUS_PENDING,
                    'gateway' => $data['gateway'] ?? null,
                    'reference_number' => $data['reference_number'] ?? null,
                    'proof_path' => $data['proof_path'] ?? null,
                    'cash_account_id' => $data['cash_account_id'] ?? null,
                    'bank_account_id' => $data['bank_account_id'] ?? null,
                    'cashier_session_id' => $data['cashier_session_id'] ?? null,
                    'cost_center_id' => $data['cost_center_id'] ?? $invoice?->cost_center_id,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $data['created_by'] ?? $actor?->id,
                ]);

                $this->persistAllocations($payment, $plan['allocations']);

                if ($immediate) {
                    $this->settle($payment, $actor, $data['wallet_id'] ?? null, $this->carbonize($data['paid_at'] ?? null));
                } else {
                    $this->notifications->paymentPendingVerification($payment);
                }

                return $payment->fresh(['invoice', 'billing', 'receipt', 'cashAccount', 'bankAccount', 'allocations']);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Dua permintaan paralel dengan kunci yang sama: yang kalah membaca
            // hasil yang menang, bukan menghasilkan pembayaran kedua.
            $concurrent = $idempotencyKey === null
                ? null
                : $this->findByIdempotencyKey($creatorId, $idempotencyKey);

            if ($concurrent === null) {
                throw $exception;
            }

            return $concurrent;
        }
    }

    /** Kunci dari pemanggil: teks kosong berarti "tidak pakai kunci". */
    private function idempotencyKey(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 100);
    }

    /** Kunci terikat pada pembuatnya — dua kasir berbeda tidak saling menahan. */
    private function findByIdempotencyKey(?int $creatorId, string $key): ?FinancePayment
    {
        return FinancePayment::query()
            ->when($creatorId === null,
                fn ($query) => $query->whereNull('created_by'),
                fn ($query) => $query->where('created_by', $creatorId))
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * Verifikasi payment `pending` menjadi lunas (§21). Baru di titik ini saldo
     * invoice bergerak dan jurnal diposting.
     */
    public function verify(FinancePayment $payment, ?User $actor = null, ?int $walletId = null): FinancePayment
    {
        return DB::transaction(function () use ($payment, $actor, $walletId): FinancePayment {
            $locked = FinancePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            FinanceStatusMachine::assert($locked, FinancePayment::STATUS_PAID, 'payment');

            $this->assertSettlementAccount($locked->method, [
                'cash_account_id' => $locked->cash_account_id,
                'bank_account_id' => $locked->bank_account_id,
            ]);

            $locked->verified_by = $actor?->id;
            $locked->verified_at = Carbon::now();
            $locked->save();

            $this->logVerification($locked, FinancePaymentVerification::ACTION_VERIFIED, $actor);

            $this->settle($locked, $actor, $walletId);

            return $locked->fresh(['invoice', 'billing', 'receipt', 'cashAccount', 'bankAccount']);
        });
    }

    /**
     * Tolak payment `pending` (bukti transfer tidak valid, dsb). Tidak ada jurnal
     * yang perlu dibalik karena belum pernah diposting.
     *
     * `providerReported` menandai bahwa yang menolak bukan manusia melainkan kanal
     * bayarnya sendiri (webhook `failed`). Statusnya sama-sama `failed` — yang
     * berbeda hanya pesan ke wali, karena "kanalnya gagal" tidak sama artinya
     * dengan "pembayaran Anda kami nilai tidak sah" (§8).
     */
    public function reject(FinancePayment $payment, string $reason, ?User $actor = null, bool $providerReported = false): FinancePayment
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan penolakan wajib diisi.']);
        }

        return DB::transaction(function () use ($payment, $reason, $actor, $providerReported): FinancePayment {
            $locked = FinancePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            FinanceStatusMachine::assert($locked, FinancePayment::STATUS_FAILED, 'payment');

            $locked->status = FinancePayment::STATUS_FAILED;
            $locked->reject_reason = $reason;
            $locked->verified_by = $actor?->id;
            $locked->verified_at = Carbon::now();
            $locked->save();

            $this->logVerification($locked, FinancePaymentVerification::ACTION_REJECTED, $actor, $reason);

            $this->notifications->paymentRejected($locked, $providerReported);

            return $locked->fresh();
        });
    }

    /**
     * Tandai payment `pending` kedaluwarsa (gateway/VA lewat batas). Tanpa jurnal.
     */
    public function expire(FinancePayment $payment): FinancePayment
    {
        return DB::transaction(function () use ($payment): FinancePayment {
            $locked = FinancePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            // Dipanggil scheduler untuk seluruh payment kedaluwarsa: yang sudah
            // selesai bukan kesalahan, hanya baris yang lewat lebih awal.
            if (! FinanceStatusMachine::can($locked, FinancePayment::STATUS_EXPIRED)) {
                return $locked;
            }

            $locked->status = FinancePayment::STATUS_EXPIRED;
            $locked->save();

            $this->logVerification($locked, FinancePaymentVerification::ACTION_EXPIRED, null);

            $this->notifications->paymentExpired($locked);

            return $locked->fresh();
        });
    }

    /**
     * Tutup pembayaran kanal yang batas waktunya lewat dan uangnya tidak pernah
     * masuk (§7): `pending → expired`. Dijalankan scheduler, bukan per-request.
     *
     * Hanya baris yang punya `expires_at` yang ikut serta — kolom itu diisi oleh
     * charge gateway/VA. Transfer manual menunggu keputusan petugas, bukan jam,
     * jadi tidak pernah kedaluwarsa dengan sendirinya. Tiap baris lewat expire()
     * (bukan UPDATE massal) supaya penjaga status, jejak verifikasi, dan
     * pengingatnya tetap sama dengan aksi petugas.
     *
     * @return array{scanned: int, expired: int}
     */
    public function expireStale(?Carbon $asOf = null): array
    {
        $deadline = ($asOf ?? Carbon::now())->toDateTimeString();

        $payments = FinancePayment::query()
            ->where('status', FinancePayment::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $deadline)
            ->orderBy('expires_at')
            ->get();

        $expired = 0;

        foreach ($payments as $payment) {
            // Webhook yang tiba di antara daftar dan transisi sudah menyelesaikan
            // barisnya; expire() menolaknya lewat mesin status, dan itu bukan
            // kesalahan — hanya dokumen yang tidak lagi menunggu.
            if ($this->expire($payment)->status === FinancePayment::STATUS_EXPIRED) {
                $expired++;
            }
        }

        return ['scanned' => $payments->count(), 'expired' => $expired];
    }

    /**
     * Batalkan payment yang sudah lunas (§25, §29). Jurnal dibalik (reversal),
     * penerapan ke invoice ditarik, kuitansi ditandai batal. Payment asli tetap
     * tersimpan sebagai jejak audit — tidak pernah dihapus.
     */
    public function cancel(FinancePayment $payment, string $reason, ?User $actor = null): FinancePayment
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan pembatalan wajib diisi.']);
        }

        return DB::transaction(function () use ($payment, $reason, $actor): FinancePayment {
            $locked = FinancePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            FinanceStatusMachine::assert($locked, FinancePayment::STATUS_CANCELLED, 'payment');

            $amount = round((float) $locked->amount, 2);

            $this->reverseSettlementJournal($locked, $reason, $actor);
            $this->unapply($locked);
            $this->cancelReceipt($locked, $reason, $actor);

            $locked->status = FinancePayment::STATUS_CANCELLED;
            $locked->reject_reason = $reason;
            $locked->save();

            $this->logVerification($locked, FinancePaymentVerification::ACTION_CANCELLED, $actor, $reason);

            $this->notifications->paymentCancelled($locked);

            return $locked->fresh(['invoice', 'billing', 'receipt']);
        });
    }

    // ==================== Internal: settlement ====================

    /**
     * Selesaikan pembayaran: (wallet → debit saldo), posting jurnal kas masuk,
     * terapkan ke invoice/billing, terbitkan kuitansi. Dipanggil dalam transaksi.
     */
    private function settle(FinancePayment $payment, ?User $actor, ?int $walletId = null, ?string $paidAt = null): void
    {
        // Pintu tunggal menuju `paid`: jurnal, saldo invoice, dan kuitansi hanya
        // lahir sekali karena perpindahan statusnya diverifikasi lebih dulu (§3,
        // §6). Pembayaran yang sudah lunas tidak bisa "diselesaikan" dua kali.
        FinanceStatusMachine::assert($payment, FinancePayment::STATUS_PAID, 'payment');

        $amount = round((float) $payment->amount, 2);
        $paidAt = $paidAt ?? Carbon::now()->toDateTimeString();

        if ($payment->method === FinancePayment::METHOD_WALLET) {
            $this->debitWallet($payment, $walletId, $amount, $actor);
        }

        $account = $this->settlementAccount($payment);

        $allocations = FinancePaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->orderBy('id')
            ->get();

        $this->postSettlementJournal($payment, $account, $allocations, $paidAt, $actor);

        foreach ($allocations as $allocation) {
            $this->applyAllocation($allocation);
        }

        $payment->status = FinancePayment::STATUS_PAID;
        $payment->paid_at = Carbon::parse($paidAt);
        $payment->verified_at = $payment->verified_at ?? Carbon::parse($paidAt);
        $payment->verified_by = $payment->verified_by ?? $actor?->id;
        $payment->save();

        $this->issueReceipt($payment, $actor);

        $this->notifications->paymentReceived($payment->load('receipt'));
    }

    /**
     * Posting jurnal kas masuk lewat lapisan akuntansi (§6, §12–§17). Service ini
     * hanya menyediakan dokumen + akun konkret; susunan debit/kredit, basis
     * pengakuan, dan perlakuan akun clearing gateway adalah milik JournalPostingService.
     *
     * §19: pembayaran yang belum confirmed tidak pernah memanggil sini, jadi tidak
     * ada jurnal posted untuk payment `pending`.
     *
     * @param  Collection<int, FinancePaymentAllocation>  $allocations
     */
    private function postSettlementJournal(
        FinancePayment $payment,
        FinanceCashAccount|FinanceBankAccount|null $account,
        Collection $allocations,
        string $paidAt,
        ?User $actor,
    ): void {
        $this->posting->paymentSettlement($payment, $account, $allocations, Carbon::parse($paidAt), $actor);
    }

    /** Terapkan satu baris alokasi ke invoice (+ billing induknya). */
    private function applyAllocation(FinancePaymentAllocation $allocation): void
    {
        $amount = round((float) $allocation->amount, 2);

        if ($amount == 0.0) {
            return;
        }

        $invoice = FinanceInvoice::query()->whereKey($allocation->invoice_id)->lockForUpdate()->first();

        if ($invoice === null) {
            return;
        }

        $this->applyToInvoice($invoice, $amount);

        if ($invoice->billing_id !== null) {
            $billing = FinanceBilling::query()->whereKey($invoice->billing_id)->lockForUpdate()->first();

            if ($billing !== null) {
                $this->applyToBilling($billing, $amount);
            }
        }
    }

    private function applyToInvoice(FinanceInvoice $invoice, float $amount): void
    {
        $this->balances->applyPaidDelta($invoice, $amount);

        // Daftar Ulang (§6, §32): status workflow mengikuti invoice, tidak
        // dipercaya mentah. Berlaku dua arah (pembayaran & pembatalan).
        $this->reRegistration->syncFromInvoice($invoice);
    }

    private function applyToBilling(FinanceBilling $billing, float $amount): void
    {
        $this->balances->applyPaidDelta($billing, $amount);
    }

    /**
     * Tarik kembali penerapan pembayaran saat pembatalan. Setiap baris alokasi
     * dibalik nominalnya ke invoice/billing; baris alokasi tetap tersimpan
     * sebagai jejak audit (§29).
     */
    private function unapply(FinancePayment $payment): void
    {
        $allocations = FinancePaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->orderBy('id')
            ->get();

        foreach ($allocations as $allocation) {
            $allocated = round((float) $allocation->amount, 2);

            if ($allocated == 0.0) {
                continue;
            }

            $invoice = FinanceInvoice::query()->whereKey($allocation->invoice_id)->lockForUpdate()->first();

            if ($invoice === null) {
                continue;
            }

            $this->applyToInvoice($invoice, -$allocated);

            if ($invoice->billing_id !== null) {
                $billing = FinanceBilling::query()->whereKey($invoice->billing_id)->lockForUpdate()->first();

                if ($billing !== null) {
                    $this->applyToBilling($billing, -$allocated);
                }
            }
        }
    }

    /** Balik jurnal kas masuk payment (§51) — jurnal asli tak diubah nominalnya. */
    private function reverseSettlementJournal(FinancePayment $payment, string $reason, ?User $actor): void
    {
        $journal = FinancialTransaction::query()
            ->where('reference_type', FinancePayment::class)
            ->where('reference_id', $payment->id)
            ->where('status', FinancialTransaction::STATUS_POSTED)
            ->first();

        if ($journal === null) {
            return;
        }

        $account = $this->settlementAccount($payment);
        $balances = $account !== null
            ? [['account' => $account, 'delta' => -round((float) $payment->amount, 2)]]
            : [];

        $this->posting->reverse($journal, $reason, $actor, $balances);
    }

    // ==================== Internal: kuitansi (§24, §25) ====================

    /** Terbitkan kuitansi untuk payment confirmed. Idempoten per payment. */
    private function issueReceipt(FinancePayment $payment, ?User $actor): FinanceReceipt
    {
        $existing = FinanceReceipt::query()
            ->where('payment_id', $payment->id)
            ->where('is_cancelled', false)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return FinanceReceipt::create([
            'receipt_number' => $this->numbering->next(FinanceNumberingService::CODE_RECEIPT),
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'customer_type' => $payment->customer_type,
            'customer_id' => $payment->customer_id,
            'student_id' => $payment->student_id,
            'amount' => $payment->amount,
            'method' => $payment->method,
            'received_at' => $payment->paid_at ?? Carbon::now(),
            'is_cancelled' => false,
            'notes' => $payment->notes,
            'created_by' => $actor?->id ?? $payment->created_by,
        ]);
    }

    /**
     * Batalkan kuitansi payment yang dibatalkan (§25). Kuitansi TIDAK dihapus —
     * hanya ditandai `is_cancelled` beserta alasan dan waktunya.
     */
    private function cancelReceipt(FinancePayment $payment, string $reason, ?User $actor): void
    {
        $receipt = FinanceReceipt::query()
            ->where('payment_id', $payment->id)
            ->where('is_cancelled', false)
            ->lockForUpdate()
            ->first();

        if ($receipt === null) {
            return;
        }

        $this->voidReceipt($receipt, $reason);
    }

    /**
     * Batalkan satu dokumen kuitansi (§25) — koreksi administratif. Kuitansi
     * TIDAK dihapus; hanya ditandai batal beserta alasan dan waktunya. Pembalikan
     * uang/jurnal adalah tugas pembatalan payment, bukan endpoint ini.
     */
    public function cancelReceiptDocument(FinanceReceipt $receipt, string $reason, ?User $actor = null): FinanceReceipt
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan pembatalan kuitansi wajib diisi.']);
        }

        return DB::transaction(function () use ($receipt, $reason): FinanceReceipt {
            $locked = FinanceReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_cancelled) {
                throw ValidationException::withMessages(['receipt' => 'Kuitansi sudah dibatalkan sebelumnya.']);
            }

            $this->voidReceipt($locked, $reason);

            return $locked->fresh();
        });
    }

    private function voidReceipt(FinanceReceipt $receipt, string $reason): void
    {
        $receipt->is_cancelled = true;
        $receipt->cancelled_at = Carbon::now();
        $receipt->cancel_reason = $reason;
        $receipt->save();
    }

    // ==================== Internal: wallet (§61) ====================

    /**
     * Debit saldo wallet santri/wali sebagai sumber dana pembayaran. Mencatat
     * transaksi di buku wallet (terpisah dari Finance) — Finance tetap memposting
     * jurnalnya sendiri ke akun penampung e-wallet.
     */
    private function debitWallet(FinancePayment $payment, ?int $walletId, float $amount, ?User $actor): void
    {
        $wallet = $walletId !== null
            ? Wallet::query()->whereKey($walletId)->first()
            : $this->guessWallet($payment);

        if ($wallet === null) {
            throw ValidationException::withMessages([
                'wallet_id' => 'Wallet tidak ditemukan untuk pembayaran dari saldo wallet.',
            ]);
        }

        $this->walletLedger->post($wallet, WalletTransaction::TYPE_WITHDRAW, $amount, [
            'reference' => $payment,
            'description' => "Pembayaran tagihan {$payment->payment_number}",
            'metadata' => ['finance_payment_id' => $payment->id, 'module' => 'finance'],
            'created_by' => $actor?->id ?? $payment->created_by,
            'approved_by' => $actor?->id ?? $payment->created_by,
        ]);
    }

    private function guessWallet(FinancePayment $payment): ?Wallet
    {
        if ($payment->student_id !== null) {
            $wallet = Wallet::query()->where('student_id', $payment->student_id)->where('status', Wallet::STATUS_ACTIVE)->first();

            if ($wallet !== null) {
                return $wallet;
            }
        }

        if ($payment->customer_type === 'guardian') {
            return Wallet::query()->where('guardian_id', $payment->customer_id)->where('status', Wallet::STATUS_ACTIVE)->first();
        }

        return null;
    }

    // ==================== Internal: validasi & helper ====================

    /**
     * Susun rencana alokasi pembayaran (§15–§19). Menerima `allocations` eksplisit
     * (1 payment → banyak invoice) atau `invoice_id`/`billing_id` tunggal. Kelebihan
     * bayar diperlakukan sesuai `overpayment_policy` (§19): `reject` (default)
     * menolak, selain itu ditahan sebagai deposit dan tidak mengurangi invoice.
     *
     * @return array{
     *     allocations: list<array{invoice_id: int, amount: float}>,
     *     primaryInvoice: FinanceInvoice|null,
     *     primaryBillingId: int|null
     * }
     */
    private function planAllocations(array $data, float $amount): array
    {
        $policy = $this->overpaymentPolicy();
        $raw = $data['allocations'] ?? null;

        if (is_array($raw) && $raw !== []) {
            return $this->planFromExplicit($raw, $amount, $policy);
        }

        return $this->planFromSingle($data, $amount, $policy);
    }

    /**
     * @param  array<int, array{invoice_id: int, amount: float}>  $raw
     * @return array{allocations: list<array{invoice_id: int, amount: float}>, primaryInvoice: FinanceInvoice|null, primaryBillingId: int|null}
     */
    private function planFromExplicit(array $raw, float $amount, string $policy): array
    {
        $allocations = [];
        $seen = [];
        $total = 0.0;
        $primary = null;

        foreach ($raw as $row) {
            $invoiceId = (int) ($row['invoice_id'] ?? 0);
            $rowAmount = round((float) ($row['amount'] ?? 0), 2);

            if ($invoiceId <= 0) {
                throw ValidationException::withMessages(['allocations' => 'Setiap alokasi wajib menyebut invoice_id.']);
            }

            if ($rowAmount <= 0) {
                throw ValidationException::withMessages(['allocations' => 'Nominal alokasi harus lebih dari nol.']);
            }

            if (isset($seen[$invoiceId])) {
                throw ValidationException::withMessages(['allocations' => 'Invoice tidak boleh dialokasikan dua kali dalam satu pembayaran.']);
            }

            $seen[$invoiceId] = true;

            $invoice = FinanceInvoice::query()->whereKey($invoiceId)->lockForUpdate()->first();

            if ($invoice === null) {
                throw ValidationException::withMessages(['allocations' => "Invoice #{$invoiceId} tidak ditemukan."]);
            }

            if ($invoice->status === FinanceInvoice::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['allocations' => "Invoice {$invoice->invoice_number} dibatalkan tidak bisa dibayar."]);
            }

            $remaining = round((float) $invoice->remaining_amount, 2);

            if ($rowAmount > $remaining + 0.0001) {
                throw ValidationException::withMessages([
                    'allocations' => "Alokasi ke invoice {$invoice->invoice_number} melebihi sisa tagihan (Rp ".number_format($remaining, 0, ',', '.').').',
                ]);
            }

            $allocations[] = ['invoice_id' => $invoiceId, 'amount' => $rowAmount];
            $total = round($total + $rowAmount, 2);
            $primary ??= $invoice;
        }

        if ($total > $amount + 0.0001) {
            throw ValidationException::withMessages([
                'allocations' => 'Total alokasi melebihi nominal pembayaran.',
            ]);
        }

        $excess = round($amount - $total, 2);

        if ($excess > 0.0001 && $policy === 'reject') {
            throw ValidationException::withMessages([
                'amount' => 'Nominal melebihi total sisa tagihan yang dialokasikan (Rp '.number_format($total, 0, ',', '.').').',
            ]);
        }

        return ['allocations' => $allocations, 'primaryInvoice' => $primary, 'primaryBillingId' => null];
    }

    /**
     * @return array{allocations: list<array{invoice_id: int, amount: float}>, primaryInvoice: FinanceInvoice|null, primaryBillingId: int|null}
     */
    private function planFromSingle(array $data, float $amount, string $policy): array
    {
        $invoice = $this->resolveInvoice($data);

        if ($invoice === null) {
            return ['allocations' => [], 'primaryInvoice' => null, 'primaryBillingId' => $data['billing_id'] ?? null];
        }

        $remaining = round((float) $invoice->remaining_amount, 2);

        if ($policy === 'reject') {
            $this->assertWithinRemaining($invoice, $amount);

            return [
                'allocations' => [['invoice_id' => $invoice->id, 'amount' => $amount]],
                'primaryInvoice' => $invoice,
                'primaryBillingId' => $invoice->billing_id,
            ];
        }

        // Kebijakan non-reject: alokasikan maksimal sebesar sisa tagihan; kelebihan
        // ditahan sebagai deposit (§19) dan tidak membuat invoice negatif.
        $allocate = round(min($amount, max(0, $remaining)), 2);
        $allocations = $allocate > 0.0001
            ? [['invoice_id' => $invoice->id, 'amount' => $allocate]]
            : [];

        return [
            'allocations' => $allocations,
            'primaryInvoice' => $invoice,
            'primaryBillingId' => $invoice->billing_id,
        ];
    }

    /** Tuliskan baris alokasi untuk sebuah payment (dipanggil sekali saat record). */
    private function persistAllocations(FinancePayment $payment, array $allocations): void
    {
        foreach ($allocations as $allocation) {
            FinancePaymentAllocation::create([
                'payment_id' => $payment->id,
                'invoice_id' => $allocation['invoice_id'],
                'amount' => $allocation['amount'],
            ]);
        }
    }

    /** Kebijakan kelebihan pembayaran (§19). Default `reject` agar tak pernah diam-diam negatif. */
    private function overpaymentPolicy(): string
    {
        $policy = (string) $this->settings->get('overpayment_policy', 'reject');

        return in_array($policy, ['reject', 'credit_balance', 'unallocated', 'refund'], true) ? $policy : 'reject';
    }

    /**
     * Catat jejak tindakan admin atas payment (§22, §40). Kolom di payment hanya
     * menyimpan tindakan terakhir; tabel verifikasi menyimpan seluruh riwayat.
     */
    private function logVerification(FinancePayment $payment, string $action, ?User $actor, ?string $reason = null): void
    {
        FinancePaymentVerification::create([
            'payment_id' => $payment->id,
            'action' => $action,
            'user_id' => $actor?->id ?? $payment->verified_by,
            'reason' => $reason,
        ]);
    }

    private function resolveInvoice(array $data): ?FinanceInvoice
    {
        if (! empty($data['invoice_id'])) {
            $invoice = FinanceInvoice::query()->whereKey($data['invoice_id'])->lockForUpdate()->first();

            if ($invoice === null) {
                throw ValidationException::withMessages(['invoice_id' => 'Invoice tidak ditemukan.']);
            }

            if ($invoice->status === FinanceInvoice::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['invoice_id' => 'Invoice dibatalkan tidak bisa dibayar.']);
            }

            return $invoice;
        }

        if (! empty($data['billing_id'])) {
            $invoice = FinanceInvoice::query()->where('billing_id', $data['billing_id'])->lockForUpdate()->first();

            if ($invoice === null) {
                throw ValidationException::withMessages([
                    'billing_id' => 'Billing belum punya invoice. Terbitkan invoice lebih dulu sebelum menerima pembayaran.',
                ]);
            }

            return $invoice;
        }

        return null;
    }

    private function assertWithinRemaining(FinanceInvoice $invoice, float $amount): void
    {
        $remaining = round((float) $invoice->remaining_amount, 2);

        if ($remaining <= 0.0001) {
            throw ValidationException::withMessages([
                'invoice_id' => "Invoice {$invoice->invoice_number} sudah lunas.",
            ]);
        }

        if ($amount > $remaining + 0.0001) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal melebihi sisa tagihan invoice (Rp '.number_format($remaining, 0, ',', '.').').',
            ]);
        }
    }

    /** Metode tunai butuh akun kas; metode lain (bank/VA/gateway/manual/wallet) butuh akun bank atau kas tujuan. */
    private function assertSettlementAccount(string $method, array $data): void
    {
        if ($method === FinancePayment::METHOD_CASH) {
            if (empty($data['cash_account_id'])) {
                throw ValidationException::withMessages(['cash_account_id' => 'Pembayaran tunai wajib memilih akun kas.']);
            }

            return;
        }

        if (empty($data['bank_account_id']) && empty($data['cash_account_id'])) {
            throw ValidationException::withMessages(['bank_account_id' => 'Pembayaran non-tunai wajib memilih akun bank/kas tujuan.']);
        }
    }

    private function settlementAccount(FinancePayment $payment): FinanceCashAccount|FinanceBankAccount|null
    {
        if ($payment->cash_account_id !== null) {
            return FinanceCashAccount::query()->whereKey($payment->cash_account_id)->first();
        }

        if ($payment->bank_account_id !== null) {
            return FinanceBankAccount::query()->whereKey($payment->bank_account_id)->first();
        }

        return null;
    }

    private function carbonize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value->toDateTimeString() : Carbon::parse($value)->toDateTimeString();
    }
}
