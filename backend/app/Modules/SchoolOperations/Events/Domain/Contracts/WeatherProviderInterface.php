<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * WeatherProviderInterface
 *
 * Prakiraan cuaca untuk event luar ruang (§62).
 *
 * Modul Event hanya melakukan orkestrasi: seluruh data pokok tetap dimiliki
 * modul asalnya. Bila sumber belum terpasang, implementasi wajib menyatakan
 * `available() === false` beserta alasannya — bukan mengarang data.
 */
interface WeatherProviderInterface
{
    /** Prakiraan cuaca. */
    public function forecast(string $date, ?string $location = null): array;

    /** Apakah penyedia cuaca tersedia. */
    public function isAvailable(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
