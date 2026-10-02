<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Finance (§52, §63). Modul ini tidak membuat ledger keuangan sendiri.
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface FinanceGatewayInterface
{
    /** Catat penerimaan (kotak donasi, kontribusi qurban). */
    public function postCollection(array $payload): array;

    /** Catat beban kegiatan keagamaan. */
    public function postExpense(array $payload): array;

    /** Status anggaran program. */
    public function budgetStatus(string $reference): ?array;

    /** Ketersediaan modul keuangan. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
