<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * CateringProviderInterface
 *
 * Katering & kebutuhan makan (§48).
 *
 * Modul Event hanya melakukan orkestrasi: seluruh data pokok tetap dimiliki
 * modul asalnya. Bila sumber belum terpasang, implementasi wajib menyatakan
 * `available() === false` beserta alasannya — bukan mengarang data.
 */
interface CateringProviderInterface
{
    /** Mengajukan kebutuhan makan. */
    public function requestMeal(array $payload): array;

    /** Konfirmasi produksi. */
    public function confirmProduction(int $requestId, array $payload = []): array;

    /** Status permintaan katering. */
    public function status(int $requestId): array;

    /** Apakah modul katering tersedia. */
    public function isAvailable(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
