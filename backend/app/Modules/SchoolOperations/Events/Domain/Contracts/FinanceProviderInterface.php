<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * FinanceProviderInterface
 *
 * Anggaran & realisasi biaya (§52).
 *
 * Modul Event hanya melakukan orkestrasi: seluruh data pokok tetap dimiliki
 * modul asalnya. Bila sumber belum terpasang, implementasi wajib menyatakan
 * `available() === false` beserta alasannya — bukan mengarang data.
 */
interface FinanceProviderInterface
{
    /** Mengajukan anggaran event. */
    public function createBudgetRequest(int $eventId, array $lines, array $meta = []): array;

    /** Mencatat realisasi biaya. */
    public function postCost(int $eventId, array $payload): array;

    /** Rekonsiliasi keuangan event. */
    public function reconcile(int $eventId, array $payload = []): array;

    /** Apakah modul keuangan tersedia. */
    public function isAvailable(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
