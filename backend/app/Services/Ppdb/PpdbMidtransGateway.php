<?php

namespace App\Services\Ppdb;

use App\Models\PpdbInvoice;
use App\Models\PpdbPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Jembatan pembayaran online Midtrans (Snap).
 *
 * Konfigurasi dibaca dari `config('services.midtrans.*')` dengan fallback ke
 * environment `MIDTRANS_*`; semuanya punya nilai default sehingga aplikasi tetap
 * jalan ketika gateway belum dipasang (portal hanya menampilkan metode manual).
 *
 * Yang disediakan hanya dua hal:
 * 1. `createTransaction()` — membuat transaksi Snap dan mengembalikan
 *    `redirect_url` / `snap_token`.
 * 2. `verifyNotification()` + `statusFor()` — memverifikasi & memetakan notifikasi
 *    Core API ke status pembayaran lokal.
 *
 * Tidak ada penyimpanan token atau retry di sini; rekonsiliasi tetap memakai
 * kolom `provider` dan `provider_reference` pada `ppdb_payments`.
 */
class PpdbMidtransGateway
{
    public const PROVIDER = 'midtrans';

    public const SANDBOX_URL = 'https://app.sandbox.midtrans.com';

    public const PRODUCTION_URL = 'https://app.midtrans.com';

    /** Rute notifikasi Core API yang boleh diproses. */
    public const NOTIFICATION_ROUTES = ['/payments/notifications', '/notifications', '/v2/payments/notifications'];

    /**
     * Apakah gateway siap dipakai (server key terpasang).
     */
    public function isConfigured(): bool
    {
        return filled($this->serverKey());
    }

    public function serverKey(): ?string
    {
        return $this->setting('server_key', 'MIDTRANS_SERVER_KEY');
    }

    /**
     * Apakah notifikasi TANPA tanda tangan boleh diproses?
     *
     * Hanya untuk mock/uji lokal dan harus dinyalakan sadar-sadaran lewat
     * `MIDTRANS_ALLOW_UNSIGNED_NOTIFICATIONS=true`; bila tidak, callback publik
     * menolak semua notifikasi tanpa tanda tangan sehingga tidak ada pihak anonim
     * yang bisa melunasi tagihan hanya dengan menebak nomor tagihan.
     */
    public function allowsUnsigned(): bool
    {
        $value = $this->setting('allow_unsigned_notifications', 'MIDTRANS_ALLOW_UNSIGNED_NOTIFICATIONS') ?? false;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function clientKey(): ?string
    {
        return $this->setting('client_key', 'MIDTRANS_CLIENT_KEY');
    }

    public function baseUrl(): string
    {
        $url = $this->setting('url', 'MIDTRANS_BASE_URL');

        if (filled($url)) {
            return rtrim((string) $url, '/');
        }

        $production = filter_var($this->setting('is_production', 'MIDTRANS_IS_PRODUCTION') ?? false, FILTER_VALIDATE_BOOLEAN);

        return $production ? self::PRODUCTION_URL : self::SANDBOX_URL;
    }

    /**
     * Buat transaksi Snap untuk satu tagihan.
     *
     * @param  array<string, mixed>  $context  nama & kontak pembeli
     * @return array{redirect_url: string, snap_token: string, order_id: string, gross_amount: float}
     *
     * @throws RuntimeException bila gateway belum dikonfigurasi atau menolak permintaan
     */
    public function createTransaction(PpdbInvoice $invoice, array $context = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Pembayaran online belum diaktifkan. Gunakan metode transfer manual atau hubungi panitia PPDB.',
            );
        }

        $response = Http::baseUrl($this->baseUrl())
            ->withBasicAuth((string) $this->serverKey(), '')
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 200, throw: false)
            ->post('/snap/v1/transaction', [
                'transaction_details' => [
                    'order_id' => $invoice->number,
                    'gross_amount' => (int) round($invoice->outstandingAmount()),
                ],
                'enabled_payments' => ['bank_transfer', 'echannel', 'qris', 'gopay', 'shopeepay'],
                'customer_details' => [
                    'first_name' => $context['name'] ?? 'Calon Siswa',
                    'email' => $context['email'] ?? null,
                    'phone' => $context['phone'] ?? null,
                ],
                'item_details' => $context['items'] ?? [],
                'metadata' => [
                    'registration_number' => $context['registration_number'] ?? null,
                ],
            ]);

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            throw new RuntimeException('Pembayaran online tidak dapat dibuat saat ini: '
                .($body['error_message'] ?? 'gateway merespons dengan status '.$response->status()).'.');
        }

        return [
            'redirect_url' => (string) ($body['redirect_url'] ?? ''),
            'snap_token' => (string) ($body['token'] ?? ''),
            'order_id' => $invoice->number,
            'gross_amount' => (float) ($body['transaction_details']['gross_amount'] ?? $invoice->outstandingAmount()),
        ];
    }

    /**
     * Verifikasi notifikasi Core API.
     *
     * `SHA512(order_id + status_code + gross_amount + server_key)`, dibandingkan
     * waktu-konstan. Rute callback ini publik dan satu-satunya penjaga pintu, jadi
     * notifikasi tanpa tanda tangan tidak pernah dipercaya otomatis — termasuk saat
     * server key belum dipasang. Mode tanpa tanda tangan hanya jalan bila
     * `allowsUnsigned()` memang dinyalakan (uji lokal/mock).
     */
    public function verifyNotification(Request $request): bool
    {
        if (! $this->isConfigured()) {
            return $this->allowsUnsigned();
        }

        $route = (string) $request->header('X-Route', '');

        if ($route !== '' && ! in_array($route, self::NOTIFICATION_ROUTES, true)) {
            return false;
        }

        $payload = $this->notificationPayload($request);

        $signature = (string) ($payload['signature'] ?? '');
        $order = (string) data_get($payload, 'transaction_details.order_id', '');
        $statusCode = (string) data_get($payload, 'transaction_details.status_code', '');
        $gross = (string) data_get($payload, 'transaction_details.gross_amount', '');

        if ($signature === '' || $order === '') {
            return false;
        }

        $expected = hash('sha512', $order.$statusCode.$gross.$this->serverKey());

        return hash_equals($expected, strtolower($signature));
    }

    /**
     * Muat body notifikasi; Midtrans mengirim string JSON pada beberapa kanal.
     *
     * @return array<string, mixed>
     */
    public function notificationPayload(Request $request): array
    {
        $content = $request->getContent();

        $decoded = $content !== '' ? json_decode($content, true) : null;

        if (is_array($decoded)) {
            return $decoded + $request->query();
        }

        return $request->all();
    }

    /**
     * transaction_status Midtrans → status pembayaran lokal.
     */
    public function statusFor(string $transactionStatus): ?string
    {
        return match (strtolower($transactionStatus)) {
            'settlement', 'capture' => PpdbPayment::STATUS_PAID,
            'pending' => PpdbPayment::STATUS_PENDING,
            'expire', 'expiration' => PpdbPayment::STATUS_EXPIRED,
            'cancel', 'failure' => PpdbPayment::STATUS_FAILED,
            'refund' => PpdbPayment::STATUS_REFUNDED,
            default => null,
        };
    }

    /**
     * Urutan "kemajuan" status supaya notifikasi ulang tidak memundurkan status.
     */
    public function rankOf(?string $status): int
    {
        return match ($status) {
            null => 0,
            PpdbPayment::STATUS_PENDING => 1,
            PpdbPayment::STATUS_FAILED, PpdbPayment::STATUS_EXPIRED => 2,
            PpdbPayment::STATUS_PAID => 3,
            PpdbPayment::STATUS_REFUNDED => 4,
            default => 0,
        };
    }

    private function setting(string $key, string $env): ?string
    {
        $value = config('services.midtrans.'.$key) ?? env($env);

        return $value === null || $value === '' ? null : (string) $value;
    }
}
