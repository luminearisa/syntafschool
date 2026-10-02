<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * ProcurementProviderInterface
 *
 * Pengadaan (§56).
 *
 * Modul Event hanya melakukan orkestrasi: seluruh data pokok tetap dimiliki
 * modul asalnya. Bila sumber belum terpasang, implementasi wajib menyatakan
 * `available() === false` beserta alasannya — bukan mengarang data.
 */
interface ProcurementProviderInterface
{
    /** Mengajukan permintaan pengadaan. */
    public function request(array $payload): array;

    /** Status permintaan pengadaan. */
    public function status(int $requestId): array;

    /** Apakah modul pengadaan tersedia. */
    public function isAvailable(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
