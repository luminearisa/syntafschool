<?php

namespace App\Services\Ppdb;

use App\Models\PpdbInvoice;
use App\Models\PpdbPayment;
use App\Models\User;
use App\Notifications\PpdbActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Pembayaran tagihan PPDB: manual (bukti transfer + verifikasi petugas) dan
 * Midtrans (online, lewat callback).
 *
 * Master `payment_methods` dipakai ulang — tidak ada mesin pembayaran atau
 * pembukuan baru. Nominal akhir selalu dihitung ulang dari pembayaran berstatus
 * `paid` oleh `PpdbInvoiceService::settle()`, sehingga pembayaran sebagian,
 * penolakan, dan callback yang datang dua kali tidak pernah menghasilkan piutang
 * yang berbeda.
 */
class PpdbPaymentService
{
    /** Type notifikasi internal: bukti bayar menunggu verifikasi panitia. */
    public const NOTIFY_PENDING = 'payment_pending_verification';

    public function __construct(
        private readonly PpdbInvoiceService $invoices,
        private readonly PpdbMidtransGateway $gateway,
        private readonly PpdbNotificationService $notifications,
    ) {
    }

    /**
     * Query daftar pembayaran (admin) + filter status/metode/tagihan/periode.
     */
    public function query(Request $request): Builder
    {
        return PpdbPayment::query()
            ->with([
                'invoice:id,registration_id,number,type,total_amount,paid_amount,status,due_date',
                'invoice.registration:id,registration_number,period_id,parent_account_id',
                'paymentMethod:id,name,code',
                'verifiedBy:id,name',
            ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->string('method')->toString()))
            ->when($request->filled('invoice_id'), fn ($q) => $q->where('invoice_id', (int) $request->input('invoice_id')))
            ->when($request->filled('period_id'), fn ($q) => $q->whereHas(
                'invoice.registration',
                fn ($r) => $r->where('period_id', (int) $request->input('period_id')),
            ))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->orderByDesc('id');
    }

    /**
     * Catat pembayaran yang diajukan orang tua/petugas.
     *
     * Manual  → `pending`, menunggu verifikasi panitia (bukti disimpan di disk public).
     * Midtrans→ transaksi Snap; token/redirect dikembalikan, pembayaran lokal `pending`.
     *
     * @param  array<string, mixed>  $data
     * @return array{payment: PpdbPayment, invoice: PpdbInvoice, gateway: array<string, mixed>|null}
     */
    public function submit(PpdbInvoice $invoice, array $data, ?User $actor = null): array
    {
        $this->assertPayable($invoice);

        $method = (string) ($data['method'] ?? PpdbPayment::METHOD_MANUAL);

        return $method === PpdbPayment::METHOD_MIDTRANS
            ? $this->submitMidtrans($invoice, $data, $actor)
            : $this->submitManual($invoice, $data, $actor);
    }

    /**
     * Verifikasi pembayaran manual oleh petugas.
     */
    public function verify(PpdbPayment $payment, User $actor, ?string $note = null, ?Carbon $paidAt = null): PpdbPayment
    {
        if ($payment->status !== PpdbPayment::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'payment' => 'Hanya pembayaran berstatus menunggu verifikasi yang dapat diverifikasi.',
            ]);
        }

        $invoice = $this->invoiceOf($payment);

        DB::transaction(function () use ($payment, $invoice, $actor, $note, $paidAt) {
            $payment->forceFill([
                'status' => PpdbPayment::STATUS_PAID,
                'paid_at' => $paidAt ?? Carbon::now(),
                'verified_at' => Carbon::now(),
                'verified_by' => $actor->id,
                'note' => $note !== null && $note !== '' ? $note : $payment->note,
            ])->save();

            $this->invoices->settle(
                $invoice,
                $actor,
                'payment.verified',
                'Pembayaran '.number_format((float) $payment->amount, 0, ',', '.').' diverifikasi.',
            );
        });

        return $payment->refresh();
    }

    /**
     * Tolak pembayaran manual (alasan wajib) lalu hitung ulang tagihan.
     */
    public function reject(PpdbPayment $payment, User $actor, string $reason): PpdbPayment
    {
        if ($payment->status === PpdbPayment::STATUS_PAID) {
            throw ValidationException::withMessages([
                'reason' => 'Pembayaran yang sudah dibayar tidak dapat ditolak — gunakan pengembalian dana.',
            ]);
        }

        $invoice = $this->invoiceOf($payment);

        DB::transaction(function () use ($payment, $invoice, $actor, $reason) {
            $payment->forceFill([
                'status' => PpdbPayment::STATUS_FAILED,
                'verified_at' => Carbon::now(),
                'verified_by' => $actor->id,
                'note' => $reason,
            ])->save();

            $invoice = $this->invoices->recalculateTotals($invoice);

            $this->invoices->syncRegistration($invoice, 'payment.rejected', $reason, $actor);

            $this->invoices->notifyParent(
                $invoice,
                PpdbActivityNotification::TYPE_PAYMENT_FAILED,
                'Pembayaran ditolak',
                "Bukti pembayaran tagihan {$invoice->number} ditolak: $reason. Silakan unggah ulang pembayaran Anda.",
            );
        });

        return $payment->refresh();
    }

    /**
     * Proses notifikasi Midtrans (idempoten).
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: string, message: string, payment_id?: int, invoice_id?: int}
     */
    public function handleMidtransNotification(array $payload): array
    {
        $orderId = trim((string) data_get($payload, 'transaction_details.order_id', data_get($payload, 'order_id', '')));

        if ($orderId === '') {
            return ['status' => 'ignored', 'message' => 'Notifikasi tanpa order_id diabaikan.'];
        }

        $invoice = PpdbInvoice::query()->where('number', $orderId)->first();

        if ($invoice === null) {
            return ['status' => 'not_found', 'message' => "Tagihan dengan nomor $orderId tidak ditemukan."];
        }

        $transactionStatus = (string) ($payload['transaction_status'] ?? $payload['status'] ?? '');
        $paymentStatus = $this->gateway->statusFor($transactionStatus);

        if ($paymentStatus === null) {
            return ['status' => 'ignored', 'message' => "Status \"$transactionStatus\" belum dikenali."];
        }

        $payment = $this->paymentForCallback($invoice, $payload);
        $transactionId = (string) data_get($payload, 'transaction_id', data_get($payload, 'va_number', ''));

        // Status lama/berulang diabaikan — callback Midtrans boleh dikirim dua kali.
        if ($this->gateway->rankOf($payment->status) >= $this->gateway->rankOf($paymentStatus)) {
            return [
                'status' => 'stale',
                'message' => "Status pembayaran sudah {$payment->status}; notifikasi \"$transactionStatus\" diabaikan.",
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
            ];
        }

        DB::transaction(function () use ($payment, $invoice, $paymentStatus, $transactionId, $payload, $transactionStatus) {
            $payment->forceFill([
                'status' => $paymentStatus,
                'provider' => PpdbMidtransGateway::PROVIDER,
                'provider_reference' => $transactionId !== '' ? Str::limit($transactionId, 100, '') : $payment->provider_reference,
                'paid_at' => $paymentStatus === PpdbPayment::STATUS_PAID ? ($this->transactionTime($payload) ?? Carbon::now()) : null,
                'verified_at' => $paymentStatus === PpdbPayment::STATUS_PAID ? Carbon::now() : null,
                'note' => 'Midtrans '.$transactionStatus.': '.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ])->save();

            $invoice = $this->invoices->recalculateTotals($invoice);

            $this->invoices->syncRegistration($invoice, 'payment.midtrans.'.$paymentStatus, 'Notifikasi Midtrans: '.$transactionStatus, null);

            $this->notifyCallbackResult($invoice, $paymentStatus);
        });

        return [
            'status' => 'processed',
            'message' => "Pembayaran Midtrans diproses: $paymentStatus.",
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
        ];
    }

    /**
     * Apakah kanal online (Midtrans) siap dipakai pada pemasangan ini?
     *
     * Dipakai portal untuk menampilkan/tombol "bayar online" hanya bila kredensial
     * penyedia benar-benar tersedia, sehingga orang tua tidak pernah menekan tombol
     * yang pasti gagal.
     */
    public function onlineAvailable(): bool
    {
        return $this->gateway->isConfigured();
    }

    /**
     * Simpan bukti bayar pada disk public; path relatif disimpan di `proof_path`.
     */
    public function storeProof(Request $request, PpdbInvoice $invoice): ?string
    {
        $file = $request->file('proof');

        if ($file === null) {
            return null;
        }

        $name = Carbon::now()->format('YmdHis').'-'.Str::lower(Str::random(6)).'.'.$file->getClientOriginalExtension();

        $stored = $request->file('proof')->storeAs("ppdb/payments/{$invoice->id}", $name, 'public');

        return is_string($stored) ? $stored : null;
    }

    /**
     * Tagihan milik akun orang tua dari pengguna yang sedang login.
     *
     * Pemeriksaan kepemilikan dipusatkan di `PpdbInvoiceService::ownedInvoice()`
     * agar portal punya satu pintu: akun asing 403, tagihan orang lain 404.
     */
    public function ownedInvoice(PpdbInvoice $invoice, User $user): PpdbInvoice
    {
        return $this->invoices->ownedInvoice($invoice, $user);
    }

    private function submitManual(PpdbInvoice $invoice, array $data, ?User $actor): array
    {
        $amount = round((float) $data['amount'], 2);

        $this->assertAmount($invoice, $amount);

        $payment = DB::transaction(function () use ($invoice, $amount, $data, $actor) {
            $payment = PpdbPayment::create([
                'invoice_id' => $invoice->id,
                'method' => PpdbPayment::METHOD_MANUAL,
                'amount' => $amount,
                'status' => PpdbPayment::STATUS_PENDING,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'proof_path' => $data['proof_path'] ?? null,
                'note' => $this->manualNote($invoice, $data),
                'created_by' => $actor?->id,
            ]);

            if (in_array($invoice->status, [PpdbInvoice::STATUS_UNPAID, PpdbInvoice::STATUS_FAILED, PpdbInvoice::STATUS_EXPIRED], true)) {
                $invoice->forceFill(['status' => PpdbInvoice::STATUS_PENDING, 'paid_at' => null])->save();
            }

            $this->invoices->recalculateTotals($invoice->refresh());

            $this->invoices->syncRegistration(
                $invoice->refresh(),
                'payment.submitted',
                'Bukti pembayaran sebesar Rp '.number_format($amount, 0, ',', '.').' dikirim untuk diverifikasi.',
                $actor,
            );

            return $payment;
        });

        $this->notifications->toStaff(
            'ppdb.payment.verify',
            self::NOTIFY_PENDING,
            'Menunggu verifikasi pembayaran',
            "Ada pembayaran Rp ".number_format($amount, 0, ',', '.')." pada tagihan {$invoice->number} ({$invoice->registration?->registration_number}) yang perlu diverifikasi.",
            [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'payment_id' => $payment->id,
                'registration_number' => $invoice->registration?->registration_number,
            ],
        );

        return ['payment' => $payment, 'invoice' => $invoice->refresh(), 'gateway' => null];
    }

    private function submitMidtrans(PpdbInvoice $invoice, array $data, ?User $actor): array
    {
        $amount = round((float) ($data['amount'] ?? $invoice->outstandingAmount()), 2);

        $this->assertAmount($invoice, $amount);

        if (! $this->gateway->isConfigured()) {
            throw ValidationException::withMessages([
                'method' => 'Pembayaran online (Midtrans) belum dikonfigurasi pada aplikasi ini. '
                    .'Silakan pilih metode transfer manual dan unggah bukti pembayaran.',
            ]);
        }

        $invoice->loadMissing('registration.applicant', 'registration.parentAccount.user', 'items');

        try {
            $transaction = $this->gateway->createTransaction($invoice, [
                'name' => $invoice->registration?->applicant?->full_name,
                'email' => $invoice->registration?->parentAccount?->user?->email,
                'phone' => $invoice->registration?->parentAccount?->user?->phone,
                'registration_number' => $invoice->registration?->registration_number,
                'items' => $invoice->items->map(fn ($item) => [
                    'id' => (string) $item->id,
                    'price' => (int) round((float) $item->amount),
                    'quantity' => 1,
                    'name' => $item->name,
                ])->all(),
            ]);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['method' => $exception->getMessage()]);
        }

        $payment = DB::transaction(function () use ($invoice, $amount, $data, $actor, $transaction) {
            // Satu tagihan cukup punya satu pembayaran Midtrans yang masih menggantung.
            $payment = PpdbPayment::query()
                ->where('invoice_id', $invoice->id)
                ->where('method', PpdbPayment::METHOD_MIDTRANS)
                ->where('status', PpdbPayment::STATUS_PENDING)
                ->first() ?? PpdbPayment::create([
                    'invoice_id' => $invoice->id,
                    'method' => PpdbPayment::METHOD_MIDTRANS,
                    'amount' => $amount,
                    'status' => PpdbPayment::STATUS_PENDING,
                    'payment_method_id' => $data['payment_method_id'] ?? null,
                    'provider' => PpdbMidtransGateway::PROVIDER,
                    'note' => 'Transaksi Snap dibuat dari portal.',
                    'created_by' => $actor?->id,
                ]);

            $payment->forceFill([
                'amount' => $transaction['gross_amount'] > 0 ? $transaction['gross_amount'] : $payment->amount,
                'provider' => PpdbMidtransGateway::PROVIDER,
                'note' => 'snap_token: '.$transaction['snap_token'],
            ])->save();

            if (in_array($invoice->status, [PpdbInvoice::STATUS_UNPAID, PpdbInvoice::STATUS_FAILED, PpdbInvoice::STATUS_EXPIRED], true)) {
                $invoice->forceFill(['status' => PpdbInvoice::STATUS_PENDING])->save();
            }

            $this->invoices->syncRegistration($invoice->refresh(), 'payment.checkout', 'Pembayaran online dibuat melalui Midtrans.', $actor);

            return $payment;
        });

        return [
            'payment' => $payment,
            'invoice' => $invoice->refresh(),
            'gateway' => [
                'provider' => PpdbMidtransGateway::PROVIDER,
                'redirect_url' => $transaction['redirect_url'],
                'snap_token' => $transaction['snap_token'],
                'order_id' => $transaction['order_id'],
                'gross_amount' => $transaction['gross_amount'],
                'client_key' => $this->gateway->clientKey(),
            ],
        ];
    }

    /**
     * Pembayaran target callback: cocokkan referensi penyedia, lalu pembayaran
     * Midtrans yang masih pending, terakhir buat baru (notifikasi mendahului).
     */
    private function paymentForCallback(PpdbInvoice $invoice, array $payload): PpdbPayment
    {
        $transactionId = (string) data_get($payload, 'transaction_id', '');

        if ($transactionId !== '') {
            $existing = PpdbPayment::query()->where('provider_reference', $transactionId)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $pending = PpdbPayment::query()
            ->where('invoice_id', $invoice->id)
            ->where('method', PpdbPayment::METHOD_MIDTRANS)
            ->where('status', PpdbPayment::STATUS_PENDING)
            ->latest('id')
            ->first();

        if ($pending !== null) {
            return $pending;
        }

        $gross = (float) str_replace(',', '', (string) data_get($payload, 'transaction_details.gross_amount', $invoice->total_amount));

        return PpdbPayment::create([
            'invoice_id' => $invoice->id,
            'method' => PpdbPayment::METHOD_MIDTRANS,
            'amount' => $gross > 0 ? $gross : (float) $invoice->total_amount,
            'status' => PpdbPayment::STATUS_PENDING,
            'provider' => PpdbMidtransGateway::PROVIDER,
            'created_by' => null,
        ]);
    }

    private function notifyCallbackResult(PpdbInvoice $invoice, string $paymentStatus): void
    {
        if ($paymentStatus === PpdbPayment::STATUS_PAID) {
            // `syncRegistration` sudah mengirim TYPE_PAYMENT_PAID lewat settle(); di
            // jalur callback kita kirim sendiri agar pesannya sesuai penyedia.
            $this->invoices->notifyParent(
                $invoice,
                PpdbActivityNotification::TYPE_PAYMENT_PAID,
                'Pembayaran online berhasil',
                "Pembayaran Midtrans untuk tagihan {$invoice->number} sudah diterima. "
                    .($invoice->outstandingAmount() > 0
                        ? 'Sisa tagihan: Rp '.number_format($invoice->outstandingAmount(), 0, ',', '.').'.'
                        : 'Tagihan sudah lunas.'),
            );

            return;
        }

        if (in_array($paymentStatus, [PpdbPayment::STATUS_FAILED, PpdbPayment::STATUS_EXPIRED, PpdbPayment::STATUS_REFUNDED], true)) {
            $label = match ($paymentStatus) {
                PpdbPayment::STATUS_EXPIRED => 'kedaluwarsa',
                PpdbPayment::STATUS_REFUNDED => 'dikembalikan',
                default => 'gagal',
            };

            $this->invoices->notifyParent(
                $invoice,
                PpdbActivityNotification::TYPE_PAYMENT_FAILED,
                'Pembayaran online '.$label,
                "Pembayaran Midtrans untuk tagihan {$invoice->number} $label. Silakan ulangi pembayaran atau hubungi panitia PPDB.",
            );
        }
    }

    private function transactionTime(array $payload): ?Carbon
    {
        $time = data_get($payload, 'transaction_time') ?? data_get($payload, 'settlement_time');

        if (! is_string($time) || $time === '') {
            return null;
        }

        try {
            return Carbon::parse($time);
        } catch (Throwable) {
            return null;
        }
    }

    private function invoiceOf(PpdbPayment $payment): PpdbInvoice
    {
        return $payment->invoice ?? PpdbInvoice::query()->findOrFail($payment->invoice_id);
    }

    private function manualNote(PpdbInvoice $invoice, array $data): string
    {
        $reference = $data['reference'] ?? null;

        return trim("Pengajuan pembayaran manual tagihan {$invoice->number}".($reference !== null ? " (ref: $reference)" : ''));
    }

    private function assertPayable(PpdbInvoice $invoice): void
    {
        if (in_array($invoice->status, [PpdbInvoice::STATUS_PAID, PpdbInvoice::STATUS_CANCELLED, PpdbInvoice::STATUS_REFUNDED], true)) {
            throw ValidationException::withMessages([
                'invoice' => "Tagihan {$invoice->number} tidak dapat dibayar lagi (status {$invoice->status}).",
            ]);
        }
    }

    private function assertAmount(PpdbInvoice $invoice, float $amount): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal pembayaran harus lebih besar dari nol.']);
        }

        $outstanding = $invoice->outstandingAmount();

        if ($amount > $outstanding + 0.01) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal pembayaran melebihi sisa tagihan Rp '.number_format($outstanding, 0, ',', '.').'.',
            ]);
        }
    }
}
