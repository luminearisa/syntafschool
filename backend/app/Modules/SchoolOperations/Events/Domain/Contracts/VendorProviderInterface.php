<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * VendorProviderInterface
 *
 * Manajemen vendor (§55).
 *
 * Modul Event hanya melakukan orkestrasi: seluruh data pokok tetap dimiliki
 * modul asalnya. Bila sumber belum terpasang, implementasi wajib menyatakan
 * `available() === false` beserta alasannya — bukan mengarang data.
 */
interface VendorProviderInterface
{
    /** Detail ringkas satu vendor. */
    public function find(int $vendorId): ?array;

    /** Pencarian vendor. */
    public function search(array $filters = []): array;

    /** Apakah modul vendor tersedia. */
    public function isAvailable(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
