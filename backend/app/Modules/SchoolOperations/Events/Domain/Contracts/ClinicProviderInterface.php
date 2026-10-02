<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * ClinicProviderInterface
 *
 * Dukungan medis (§59).
 *
 * Modul Event hanya melakukan orkestrasi: seluruh data pokok tetap dimiliki
 * modul asalnya. Bila sumber belum terpasang, implementasi wajib menyatakan
 * `available() === false` beserta alasannya — bukan mengarang data.
 */
interface ClinicProviderInterface
{
    /** Petugas klinik yang dapat ditugaskan. */
    public function staff(int $campusId): array;

    /** Mengajukan dukungan medis. */
    public function createSupport(array $payload): array;

    /** Apakah modul klinik tersedia. */
    public function isAvailable(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
