<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Peta provider → implementasi `PaymentGateway` (§29).
 *
 * Pemanggil (wallet) menyebut provider dengan nama — `midtrans` — dan tidak pernah
 * menyebut kelas. Menambah provider kedua berarti menambah satu baris di `DRIVERS`
 * plus kelas implementasi kontrak ini; tidak ada kode wallet yang ikut berubah dan
 * tidak ada `switch` provider yang tersebar di modul.
 *
 * Binding container atas interface `PaymentGateway` selalu dicoba lebih dulu, sehingga
 * satu titik ini cukup untuk menukar gateway tanpa menyentuh kode produksi — dipakai
 * tes untuk menjalankan alur pembayaran tanpa HTTP keluar, dan bisa dipakai produksi
 * bila suatu saat gateway datang dari paket luar.
 */
class PaymentGatewayManager
{
    /** Provider pertama sesuai spesifikasi §29. */
    public const DEFAULT_PROVIDER = MidtransGateway::PROVIDER;

    /** @var array<string, class-string<PaymentGateway>> nama provider => kelas implementasi */
    public const DRIVERS = [
        MidtransGateway::PROVIDER => MidtransGateway::class,
    ];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Nama provider yang dikenal aplikasi — termasuk yang kuncinya belum dipasang.
     *
     * @return list<string>
     */
    public function providers(): array
    {
        return array_keys(self::DRIVERS);
    }

    /**
     * Gateway untuk satu nama provider, atau null bila provider itu tidak dikenal.
     *
     * `isConfigured()` TIDAK diperiksa di sini: pemanggil perlu membedakan
     * "provider tidak ada" (salah konfigurasi aplikasi) dari "provider ada tetapi
     * kuncinya belum dipasang" (tugas bendahara, dan pesannya harus dijelaskan ke
     * pengguna yang meminta).
     */
    public function find(?string $provider): ?PaymentGateway
    {
        $provider = trim((string) $provider);

        if ($provider === '') {
            return null;
        }

        $bound = $this->bound();

        if ($bound !== null && $bound->supports($provider)) {
            return $bound;
        }

        $driver = self::DRIVERS[$provider] ?? null;

        if ($driver === null) {
            return null;
        }

        return $this->container->bound($driver) ? $this->container->make($driver) : new $driver;
    }

    /**
     * Gateway yang WAJIB bisa dipakai, atau pesan yang menjelaskan kenapa tidak.
     *
     * Melempar `RuntimeException` berkoncal Bahasa Indonesia; `WalletTopUpService`
     * mengubahnya menjadi 422 sehingga pengguna disarankan beralih ke transfer
     * manual, alih-alih dihadapkan pada layar 500.
     */
    public function resolve(?string $provider = null): PaymentGateway
    {
        $provider = trim((string) $provider);

        if ($provider !== '') {
            $gateway = $this->find($provider);

            if ($gateway === null) {
                throw new RuntimeException(sprintf(
                    'Payment gateway "%s" tidak dikenal. Penyedia yang tersedia: %s.',
                    $provider,
                    implode(', ', $this->providers()),
                ));
            }

            return $gateway;
        }

        // Tanpa provider disebut: pakai yang pertama yang benar-benar siap dipakai.
        foreach ($this->providers() as $candidate) {
            $gateway = $this->find($candidate);

            if ($gateway !== null && $gateway->isConfigured()) {
                return $gateway;
            }
        }

        throw new RuntimeException(
            'Pembayaran online belum diaktifkan pada aplikasi ini. '
            .'Pasang kredensial gateway pada .env, atau ajukan top up dengan transfer manual.',
        );
    }

    /**
     * Ringkasan per provider untuk tampilan/formulir: ada dan sudah terpasang kuncinya.
     *
     * @return list<array{provider: string, configured: bool}>
     */
    public function catalog(): array
    {
        $catalog = [];

        foreach ($this->providers() as $provider) {
            $gateway = $this->find($provider);

            $catalog[] = [
                'provider' => $provider,
                'configured' => $gateway?->isConfigured() ?? false,
            ];
        }

        return $catalog;
    }

    /** Gateway yang di-binding eksplisit pada interface kontrak, bila ada. */
    private function bound(): ?PaymentGateway
    {
        if (! $this->container->bound(PaymentGateway::class)) {
            return null;
        }

        $gateway = $this->container->make(PaymentGateway::class);

        return $gateway instanceof PaymentGateway ? $gateway : null;
    }
}
