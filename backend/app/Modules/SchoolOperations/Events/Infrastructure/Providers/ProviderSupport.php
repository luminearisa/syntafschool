<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

use Illuminate\Container\Container;
use Throwable;

/**
 * Alat bantu penyedia integrasi modul Event.
 *
 * Delegasi ke modul lain dilakukan lewat pemeriksaan kemampuan
 * (`method_exists`) karena tiap modul mendefinisikan kontraknya sendiri dengan
 * tanda tangan yang tidak seragam. Penyedia yang mendelegasikan wajib
 * menyatakan ketidaktersediaannya secara jujur, bukan mengarang data.
 *
 * Implementasi `Null*` pada modul tujuan TIDAK dianggap sebagai integrasi
 * nyata: banyak modul memasang penyedia kosong agar aplikasi dapat berjalan
 * sebelum integrasi terpasang, dan menganggapnya sebagai sumber data adalah
 * cara paling mudah menghasilkan data palsu.
 */
trait ProviderSupport
{
    /*
     * Setiap kelas pemakai WAJIB mendeklarasikan dua konstanta berikut.
     * Konstanta tidak diberi nilai bawaan di sini karena PHP 8.4 menolak
     * penimpaan konstanta trait yang tipenya berbeda (string vs null).
     *
     *   protected const TARGET_CONTRACT   = 'FQN kontrak modul lain' | null;
     *   protected const UNAVAILABLE_REASON = 'alasan yang jujur';
     */

    /** Objek kontrak tujuan, bila benar-benar terpasang. */
    protected function delegate(): ?object
    {
        $target = static::TARGET_CONTRACT;

        if ($target === null || ! interface_exists($target)) {
            return null;
        }

        try {
            $container = Container::getInstance();

            if (! $container->bound($target)) {
                return null;
            }

            $bound = $container->make($target);
        } catch (Throwable) {
            return null;
        }

        // Penyedia kosong bukan integrasi nyata.
        if ($bound === null || str_starts_with(class_basename($bound), 'Null')) {
            return null;
        }

        return $bound;
    }

    protected function has(string $method): bool
    {
        $delegate = $this->delegate();

        return $delegate !== null && method_exists($delegate, $method);
    }

    /**
     * Memanggil metode pada kontrak tujuan secara aman.
     *
     * @param  array<int, mixed>  $arguments
     */
    protected function call(string $method, array $arguments = [], mixed $fallback = null): mixed
    {
        if (! $this->has($method)) {
            return $fallback;
        }

        try {
            return $this->delegate()->{$method}(...$arguments);
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }

    /**
     * Apakah integrasi benar-benar tersedia.
     *
     * Dinamai `isAvailable` (bukan `available`) karena beberapa kontrak modul
     * lain sudah memakai `available()` dengan tanda tangan berbeda — mis.
     * AssetProviderInterface::available(array $filters): array.
     */
    public function isAvailable(): bool
    {
        return $this->delegate() !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable() ? null : static::UNAVAILABLE_REASON;
    }

    /**
     * Menyeragamkan bentuk hasil delegasi menjadi daftar.
     *
     * Modul lain mengembalikan array asosiatif (satu objek) maupun daftar;
     * pemanggil di modul Event tidak perlu mengetahui perbedaannya.
     */
    protected function wrap(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return array_is_list($value) ? $value : [$value];
        }

        return [(array) $value];
    }
}
