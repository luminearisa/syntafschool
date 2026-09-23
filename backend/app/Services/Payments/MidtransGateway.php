<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\WalletTopUp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Provider Midtrans (Snap + Core API) untuk pembayaran top up wallet.
 *
 * Konfigurasi dibaca dari `config('services.midtrans.*')` yang sudah ada di
 * aplikasi ini (`server_key`, `client_key`, `is_production`, `url`,
 * `allow_unsigned_notifications`) — tidak ada kunci .env baru dan tidak ada tabel
 * konfigurasi baru. Berkas konfigurasi itu sudah dipakai jembatan PPDB, jadi satu
 * pasang kredensial cukup untuk seluruh aplikasi.
 *
 * Yang TIDAK dilakukan kelas ini:
 * - mengenal wallet, tagihan, atau ledger. Ia hanya menerjemahkan bahasa Midtrans
 *   ke kontrak `PaymentGateway`; pemanggilnya (`WalletTopUpService`) tidak pernah
 *   memanggil kelas ini langsung, selalu lewat `PaymentGatewayManager` (§29).
 * - menyimpan state. Referensi provider dikembalikan supaya pemanggil yang
 *   menyimpannya di `wallet_topups`.
 *
 * Notifikasi tanpa tanda tangan ditolak kecuali `allow_unsigned_notifications`
 * dinyalakan sadar-sadaran (mock/uji lokal) — rute webhook publik dan tidak
 * terautentikasi, sehingga ini satu-satunya penjaga pintu.
 */
class MidtransGateway implements PaymentGateway
{
    public const PROVIDER = 'midtrans';

    public const SANDBOX_URL = 'https://app.sandbox.midtrans.com';

    public const PRODUCTION_URL = 'https://app.midtrans.com';

    /** Rute notifikasi Core API yang boleh diproses. */
    public const NOTIFICATION_ROUTES = [
        '/payments/notifications', '/notifications', '/v2/payments/notifications', '/v2/notifications',
    ];

    public function isConfigured(): bool
    {
        return filled($this->serverKey());
    }

    public function serverKey(): ?string
    {
        return $this->config('server_key');
    }

    public function clientKey(): ?string
    {
        return $this->config('client_key');
    }

    public function baseUrl(): string
    {
        $url = $this->config('url');

        if ($url !== null) {
            return rtrim($url, '/');
        }

        return filter_var((string) $this->raw('is_production'), FILTER_VALIDATE_BOOLEAN)
            ? self::PRODUCTION_URL
            : self::SANDBOX_URL;
    }

    /**
     * Apakah notifikasi TANPA tanda tangan boleh diproses?
     *
     * Default mati. Hanya untuk mock/uji lokal; bila dinyalakan di produksi, siapa
     * pun yang tahu nomor top up bisa mengklaim sudah membayar.
     */
    public function allowsUnsigned(): bool
    {
        return filter_var((string) $this->raw('allow_unsigned_notifications'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * {@inheritDoc}
     */
    public function createPayment(array $intent): array
    {
        $this->assertConfigured();

        $reference = trim((string) ($intent['reference'] ?? ''));
        $amount = round((float) ($intent['amount'] ?? 0), 2);

        if ($reference === '' || $amount <= 0) {
            throw new RuntimeException('Pembayaran tidak dapat dibuat: nomor transaksi atau nominal tidak lengkap.');
        }

        $transactionDetails = [
            'order_id' => $reference,
            'gross_amount' => (int) round($amount),
        ];

        // Snap menghormati `expiry_duration` (menit); hanya dikirim bila diminta
        // supaya tidak menimpa batas kedaluwarsa yang disetel di dashboard Midtrans.
        if (! empty($intent['expiry_minutes'])) {
            $transactionDetails['expiry_duration'] = max(1, (int) $intent['expiry_minutes']);
        }

        $payload = [
            'transaction_details' => $transactionDetails,
            'enabled_payments' => ['bank_transfer', 'echannel', 'qris', 'gopay', 'shopeepay'],
            'customer_details' => array_filter([
                'first_name' => $intent['customer']['name'] ?? 'Pemilik Wallet',
                'email' => $intent['customer']['email'] ?? null,
                'phone' => $intent['customer']['phone'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ];

        if (! empty($intent['items'])) {
            $payload['item_details'] = $intent['items'];
        }

        if (! empty($intent['metadata'])) {
            $payload['metadata'] = $intent['metadata'];
        }

        $response = Http::baseUrl($this->baseUrl())
            ->withBasicAuth((string) $this->serverKey(), '')
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 200, throw: false)
            ->post('/snap/v1/transaction', $payload);

        $body = is_array($response->json()) ? (array) $response->json() : [];

        if (! $response->successful()) {
            throw new RuntimeException('Pembayaran online tidak dapat dibuat saat ini: '
                .($body['error_message'] ?? 'gateway merespons dengan status '.$response->status()).'.');
        }

        return [
            'reference' => $this->string(data_get($body, 'transaction_details.order_id')) ?? $reference,
            'amount' => (float) str_replace(',', '', (string) data_get($body, 'transaction_details.gross_amount', $amount)),
            'token' => $this->string($body['token'] ?? null),
            'redirect_url' => $this->string($body['redirect_url'] ?? null),
            'provider_reference' => $this->string($body['transaction_id'] ?? null),
            'expires_at' => $this->string($body['expiry_time'] ?? null),
            'raw' => $body,
        ];
    }

    /**
     * Status transaksi Core API: `GET /v2/{order_id}/status`.
     *
     * Dipakai rekonsiliasi — misalnya petugas menyetujui top up gateway yang
     * pembayarannya sah tetapi notifikasinya hilang.
     */
    public function checkStatus(string $reference): array
    {
        $this->assertConfigured();

        $reference = trim($reference);

        if ($reference === '') {
            throw new RuntimeException('Nomor transaksi tidak diberikan.');
        }

        $response = Http::baseUrl($this->baseUrl())
            ->withBasicAuth((string) $this->serverKey(), '')
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 200, throw: false)
            ->get('/v2/'.rawurlencode($reference).'/status');

        $body = is_array($response->json()) ? (array) $response->json() : [];

        if (! $response->successful()) {
            throw new RuntimeException('Status pembayaran tidak dapat diperiksa saat ini: '
                .($body['status_message'] ?? 'gateway merespons dengan status '.$response->status()).'.');
        }

        return $this->normalize($reference, $body);
    }

    /**
     * {@inheritDoc}
     */
    public function handleWebhook(Request $request): array
    {
        $payload = $this->notificationPayload($request);

        return $this->normalize(
            (string) data_get($payload, 'transaction_details.order_id', data_get($payload, 'order_id', '')),
            $payload,
        );
    }

    /**
     * `SHA512(order_id + status_code + gross_amount + server_key)`, dibandingkan
     * waktu-konstan.
     *
     * Tanpa server key terpasang, satu-satunya jalan adalah mode unsigned yang
     * dinyalakan eksplisit — jadi pemasangan tanpa kredensial tidak pernah bisa
     * "dibayar" oleh pihak anonim.
     */
    public function verifySignature(Request $request): bool
    {
        if (! $this->isConfigured()) {
            return $this->allowsUnsigned();
        }

        $route = (string) $request->header('X-Route', '');

        if ($route !== '' && ! in_array($route, self::NOTIFICATION_ROUTES, true)) {
            return false;
        }

        $payload = $this->notificationPayload($request);

        $signature = strtolower((string) ($payload['signature'] ?? ''));
        $order = (string) data_get($payload, 'transaction_details.order_id', $payload['order_id'] ?? '');
        $statusCode = (string) data_get($payload, 'transaction_details.status_code', $payload['status_code'] ?? '');
        $gross = (string) data_get($payload, 'transaction_details.gross_amount', $payload['gross_amount'] ?? '');

        if ($signature === '' || $order === '') {
            return false;
        }

        $expected = hash('sha512', $order.$statusCode.$gross.(string) $this->serverKey());

        return hash_equals($expected, $signature);
    }

    public function supports(string $provider): bool
    {
        return Str::lower(trim($provider)) === self::PROVIDER;
    }

    /**
     * Payload notifikasi → bentuk normal kontrak.
     *
     * @param  array<string, mixed>  $payload
     * @return array{reference: string, status: string, amount: float, provider_reference: string|null, raw: array<string, mixed>}
     */
    private function normalize(string $reference, array $payload): array
    {
        $transactionStatus = (string) ($payload['transaction_status'] ?? $payload['status'] ?? '');

        return [
            'reference' => $reference,
            'status' => (string) ($this->paymentStatusFor($transactionStatus) ?? ''),
            'amount' => (float) str_replace(',', '', (string) data_get(
                $payload,
                'transaction_details.gross_amount',
                $payload['gross_amount'] ?? 0,
            )),
            'provider_reference' => $this->providerReference($payload),
            'raw' => $payload,
        ];
    }

    /**
     * `transaction_status` Midtrans → status pembayaran wallet (§14).
     *
     * Dipetakan ke enum `WalletTopUp::PAYMENT_STATUSES` agar pemanggil tidak perlu
     * tahu kosakata Midtrans. `authorize`/`pending`/`capture_pending` masih
     * menunggu, jadi tetap `pending`; `settlement`/`capture` baru dihitung `paid`.
     */
    public function paymentStatusFor(string $transactionStatus): ?string
    {
        return match (strtolower(trim($transactionStatus))) {
            'settlement', 'capture' => WalletTopUp::PAYMENT_PAID,
            'pending', 'authorize', 'capture_pending' => WalletTopUp::PAYMENT_PENDING,
            'expire', 'expiration' => WalletTopUp::PAYMENT_EXPIRED,
            'cancel', 'failure', 'refund_void', 'deny' => WalletTopUp::PAYMENT_FAILED,
            'refund', 'partial_refund', 'void' => WalletTopUp::PAYMENT_REFUNDED,
            default => null,
        };
    }

    /**
     * Referensi unik transaksi di sisi provider — dipakai sebagai kunci pencarian
     * webhook yang idempoten (§30).
     *
     * @param  array<string, mixed>  $payload
     */
    private function providerReference(array $payload): ?string
    {
        foreach (['transaction_id', 'va_number', 'fraud_report_id', 'bill_key'] as $key) {
            $value = $this->string($payload[$key] ?? null);

            if ($value !== null) {
                return Str::limit($value, 100, '');
            }
        }

        return null;
    }

    /**
     * Muat body notifikasi; Midtrans mengirim string JSON pada beberapa kanal, dan
     * mengirim parameter di query pada kanal lain.
     *
     * @return array<string, mixed>
     */
    public function notificationPayload(Request $request): array
    {
        $content = $request->getContent();

        try {
            $decoded = $content !== '' ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            $decoded = null;
        }

        if (is_array($decoded)) {
            return $decoded + $request->query();
        }

        return $request->all();
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Pembayaran online (Midtrans) belum dikonfigurasi pada aplikasi ini. '
                .'Isi MIDTRANS_SERVER_KEY di .env, atau ajukan top up dengan transfer manual.',
            );
        }
    }

    private function config(string $key): ?string
    {
        $value = $this->raw($key);

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function raw(string $key): mixed
    {
        return config('services.midtrans.'.$key);
    }

    /** Nilai kolom provider: string terisi, atau null (bukan '' — bedanya penting). */
    private function string(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
