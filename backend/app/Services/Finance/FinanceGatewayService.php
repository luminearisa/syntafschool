<?php

namespace App\Services\Finance;

use App\Contracts\PaymentGateway;
use App\Models\FinanceOperation;
use App\Models\FinancePayment;
use App\Models\FinancePaymentGatewayTransaction;
use App\Models\WalletTopUp;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Jembatan payment gateway untuk tagihan Finance (§20, §66).
 *
 * REUSE infrastruktur gateway yang sudah ada (`PaymentGatewayManager` +
 * kontrak `PaymentGateway`) — tidak membuat payment engine kedua. Finance hanya
 * menambahkan dua hal: membuat charge untuk sebuah `FinancePayment`, dan
 * menangani webhook secara **idempoten** (notifikasi ganda tidak pernah
 * menghasilkan pembayaran ganda).
 *
 * Kegagalan di jalur ini dilaporkan (§29): "apa yang gagal, kapan, payment mana,
 * kenapa, dan boleh diulang?" Charge dan webhook sama-sama aman dijalankan ulang
 * karena payment hanya bergerak dari `pending`, jadi incident-nya ditandai
 * `retryable`. Tanda tangan yang ditolak dicatat juga — itu bukan kegagalan
 * sistem, tapi tanpa jejaknya tidak ada yang tahu provider mengirim apa.
 */
class FinanceGatewayService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly FinancePaymentService $payments,
        private readonly FinanceSettingsService $settings,
        private readonly FinanceNotificationService $notifications,
        private readonly FinanceOperationService $operations,
    ) {}

    /**
     * Buat charge di sisi provider untuk payment `pending` metode gateway.
     * Nominal & saldo invoice belum bergerak — payment tetap `pending` sampai
     * webhook/verifikasi menandainya lunas (§21).
     *
     * @return array{reference: string, amount: float, token: string|null, redirect_url: string|null, provider_reference: string|null, expires_at: string|null}
     */
    public function charge(FinancePayment $payment, ?string $provider = null): array
    {
        $gateway = $this->gateways->resolve($provider ?? $payment->gateway);

        $locked = DB::transaction(function () use ($payment): FinancePayment {
            $row = FinancePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($row->status !== FinancePayment::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'payment' => 'Hanya pembayaran menunggu yang bisa dibuatkan charge gateway.',
                ]);
            }

            return $row;
        });

        $result = $this->callGatewayCharge($gateway, $locked, $provider);

        $locked->method = FinancePayment::METHOD_GATEWAY;
        $locked->gateway = $provider ?? $locked->gateway ?? PaymentGatewayManager::DEFAULT_PROVIDER;
        $locked->gateway_transaction_id = $result['provider_reference'];
        $locked->reference_number = $result['reference'];
        // Batas waktu kanal disimpan di dokumen, bukan hanya dikembalikan ke
        // klien: kalau tidak, `pending` milik kanal yang sudah mati tidak pernah
        // bisa kedaluwarsa dan tagihannya terbaca "sedang dibayar" selamanya (§7).
        $locked->expires_at = $this->expiryOf($result['expires_at'] ?? null);
        $locked->save();

        $this->logGatewayTransaction($locked, FinancePaymentGatewayTransaction::EVENT_CHARGE, [
            'status' => FinancePayment::STATUS_PENDING,
            'amount' => (float) $locked->amount,
            'payload' => $result,
            'handled' => true,
            'idempotency_key' => null,
        ]);

        return [
            'reference' => $result['reference'],
            'amount' => $result['amount'],
            'token' => $result['token'],
            'redirect_url' => $result['redirect_url'],
            'provider_reference' => $result['provider_reference'],
            'expires_at' => $result['expires_at'],
        ];
    }

    /**
     * Panggil provider untuk membuka kanal bayar. Kegagalan dicatat lalu dilempar
     * apa adanya (§29): payment masih `pending` dan tidak ada dokumen baru yang
     * lahir, jadi petugas cukup mencoba lagi.
     *
     * @return array{reference: string, amount: float, token: string|null, redirect_url: string|null, provider_reference: string|null, expires_at: string|null}
     */
    private function callGatewayCharge(PaymentGateway $gateway, FinancePayment $payment, ?string $provider): array
    {
        try {
            return $gateway->createPayment([
                'reference' => $payment->payment_number,
                'amount' => (float) $payment->amount,
                'description' => "Pembayaran tagihan {$payment->payment_number}",
                'customer' => ['name' => $payment->customer_type],
                'metadata' => ['finance_payment_id' => $payment->id, 'module' => 'finance'],
            ]);
        } catch (\Throwable $e) {
            $this->operations->recordIncident(
                FinanceOperation::EVENT_GATEWAY_CHARGE,
                $e,
                ['provider' => $provider ?? $payment->gateway, 'amount' => (float) $payment->amount],
                ['type' => FinancePayment::class, 'id' => (int) $payment->id, 'number' => (string) $payment->payment_number],
                retryable: true,
            );

            throw $e;
        }
    }

    /**
     * Tangani notifikasi gateway (§66). Wajib idempoten: payment yang sudah
     * lunas tidak diproses ulang, dan status tidak pernah mundur.
     *
     * @return array{handled: bool, payment_id: int|null, status: string}
     */
    public function handleWebhook(Request $request, string $provider): array
    {
        $gateway = $this->gateways->find($provider);

        if ($gateway === null) {
            throw ValidationException::withMessages(['provider' => "Provider gateway \"{$provider}\" tidak dikenal."]);
        }

        if (! $gateway->verifySignature($request)) {
            // Bukan kegagalan sistem, tapi tetap peristiwa yang harus kelihatan:
            // notifikasi yang selalu ditolak berarti integrasinya mati diam-diam.
            $this->operations->recordIncident(
                FinanceOperation::EVENT_GATEWAY_WEBHOOK,
                'Tanda tangan notifikasi gateway tidak valid.',
                ['provider' => $provider],
                retryable: false,
            );

            throw ValidationException::withMessages(['signature' => 'Tanda tangan notifikasi tidak valid.']);
        }

        $normalized = $gateway->handleWebhook($request);
        $reference = (string) ($normalized['reference'] ?? '');
        $status = (string) ($normalized['status'] ?? '');
        $providerReference = $normalized['provider_reference'] ?? null;

        if ($reference === '' || $status === '') {
            return ['handled' => false, 'payment_id' => null, 'status' => $status];
        }

        $payment = FinancePayment::query()
            ->where('payment_number', $reference)
            ->orWhere('reference_number', $reference)
            ->first();

        // Jejak webhook (§66): idempotency_key unik menahan duplikat log untuk
        // notifikasi yang sama, payload mentah tetap tersimpan untuk telusur.
        $this->logGatewayTransaction($payment, FinancePaymentGatewayTransaction::EVENT_WEBHOOK, [
            'gateway' => $provider,
            'gateway_transaction_id' => $providerReference,
            'status' => $status,
            'amount' => $payment !== null ? (float) $payment->amount : null,
            'payload' => $normalized,
            'handled' => $payment !== null,
            'idempotency_key' => $this->webhookIdempotencyKey($provider, $reference, $status, $providerReference),
        ]);

        if ($payment === null) {
            // Notifikasi sah untuk dokumen yang tidak ada di sini: uangnya ada di
            // sisi provider tapi tidak punya padanan lokal. Ini bukan kegagalan
            // sistem, jadi tidak ada yang bisa diulang — ia masuk daftar karena
            // butuh ditangani orang, bukan karena butuh tombol (§29).
            $this->operations->recordIncident(
                FinanceOperation::EVENT_GATEWAY_WEBHOOK,
                'Notifikasi gateway tidak menemukan pembayaran yang cocok.',
                ['provider' => $provider, 'status' => $status],
                ['number' => $reference],
                retryable: false,
            );

            return ['handled' => false, 'payment_id' => null, 'status' => $status];
        }

        // Kegagalan menerapkan status = uang tertahan di dokumen yang benar.
        // Jejak mentahnya sudah ada di tabel transaksi gateway; incident ini yang
        // membuat bendahara tahu ada yang perlu dijalankan ulang tanpa memindai
        // payload satu-satu (§29).
        try {
            $this->applyGatewayStatus($payment, $status, $providerReference);
        } catch (\Throwable $e) {
            $this->operations->recordIncident(
                FinanceOperation::EVENT_GATEWAY_WEBHOOK,
                $e,
                ['provider' => $provider, 'status' => $status],
                ['type' => FinancePayment::class, 'id' => (int) $payment->id, 'number' => (string) $payment->payment_number],
                retryable: true,
            );

            throw $e;
        }

        return ['handled' => true, 'payment_id' => (int) $payment->id, 'status' => $status];
    }

    /** Kunci idempotensi webhook: event provider yang sama hanya dicatat sekali. */
    private function webhookIdempotencyKey(string $provider, string $reference, string $status, ?string $providerReference): string
    {
        return substr(hash('sha256', implode('|', [$provider, $reference, $status, (string) $providerReference])), 0, 190);
    }

    /**
     * Catat satu peristiwa gateway (§20, §66). `idempotency_key` unik membuat
     * notifikasi ganda tidak menghasilkan baris log ganda (firstOrCreate).
     *
     * @param  array{gateway?: string|null, gateway_transaction_id?: string|null, status?: string|null,
     *               amount?: float|null, payload?: array|null, handled?: bool, idempotency_key?: string|null,
     *               signature_valid?: bool, created_by?: int|null}  $attrs
     */
    private function logGatewayTransaction(?FinancePayment $payment, string $eventType, array $attrs): void
    {
        $idempotencyKey = $attrs['idempotency_key'] ?? null;

        $values = [
            'payment_id' => $payment?->id,
            'gateway' => $payment?->gateway ?? $attrs['gateway'] ?? PaymentGatewayManager::DEFAULT_PROVIDER,
            'gateway_transaction_id' => $attrs['gateway_transaction_id'] ?? $payment?->gateway_transaction_id,
            'event_type' => $eventType,
            'status' => $attrs['status'] ?? null,
            'amount' => $attrs['amount'] ?? null,
            'payload' => $attrs['payload'] ?? null,
            'signature_valid' => $attrs['signature_valid'] ?? true,
            'handled' => $attrs['handled'] ?? false,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $attrs['created_by'] ?? null,
        ];

        if ($idempotencyKey !== null) {
            FinancePaymentGatewayTransaction::firstOrCreate(['idempotency_key' => $idempotencyKey], $values);

            return;
        }

        FinancePaymentGatewayTransaction::create($values);
    }

    /** Petakan status ternormalisasi provider ke aksi payment Finance (idempoten). */
    private function applyGatewayStatus(FinancePayment $payment, string $status, ?string $providerReference): void
    {
        if ($providerReference !== null && $payment->gateway_transaction_id === null) {
            $payment->gateway_transaction_id = $providerReference;
            $payment->save();
        }

        match ($status) {
            WalletTopUp::PAYMENT_PAID => $this->settleIfPending($payment),
            WalletTopUp::PAYMENT_FAILED => $this->rejectIfPending($payment, 'Pembayaran gateway gagal.', providerReported: true),
            WalletTopUp::PAYMENT_EXPIRED => $this->expireIfPending($payment),
            default => null, // unpaid/pending/refunded → tidak ada aksi di jalur webhook
        };
    }

    private function settleIfPending(FinancePayment $payment): void
    {
        if ($payment->status === FinancePayment::STATUS_PENDING) {
            $this->payments->verify($payment);

            return;
        }

        // Dana masuk pada dokumen yang sudah ditutup — biasanya scheduler baru
        // saja mengekspire kanal yang batas waktunya lewat. Jurnal tidak dipaksa
        // lewat jalur yang salah (§21: hanya payment sah yang menggerakkan kas),
        // dan tidak boleh hilang diam-diam: yang memegang hak verifikasi
        // dilaporkan supaya dananya direkonsiliasi atau direfund (§3, §29).
        if ($payment->status === FinancePayment::STATUS_EXPIRED) {
            $this->notifications->paymentLateCapture($payment);
        }
    }

    /**
     * Kapan kanal ini berhenti menunggu. Milik provider bila ia mengirimnya;
     * kalau tidak (atau tanggalnya tidak bisa dibaca), TTL konfigurasi dihitung
     * dari sekarang — scheduler tetap punya pegangan walau providernya diam.
     */
    private function expiryOf(?string $provided): ?Carbon
    {
        if (filled($provided)) {
            try {
                return Carbon::parse($provided);
            } catch (\Throwable) {
                // Format provider tidak dikenali; jatuh ke TTL konfigurasi.
            }
        }

        $hours = max(1, min(720, (int) $this->settings->get('gateway_payment_ttl_hours', 24)));

        return Carbon::now()->addHours($hours);
    }

    private function rejectIfPending(FinancePayment $payment, string $reason, bool $providerReported = false): void
    {
        if ($payment->status !== FinancePayment::STATUS_PENDING) {
            return;
        }

        $this->payments->reject($payment, $reason, providerReported: $providerReported);
    }

    private function expireIfPending(FinancePayment $payment): void
    {
        if ($payment->status !== FinancePayment::STATUS_PENDING) {
            return;
        }

        $this->payments->expire($payment);
    }
}
