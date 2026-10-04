<?php

namespace App\Modules\Procurement\Domain\Contracts;

use App\Modules\Procurement\Domain\Support\IntegrationResult;

/**
 * Budget — sumber kebenaran anggaran (pagu, komitmen, realisasi).
 *
 * Procurement tidak menyimpan pagu anggaran sendiri; ia hanya membaca
 * ketersediaan, lalu meminta **komitmen** saat PO diterbitkan dan **pelepasan**
 * komitmen saat PO dibatalkan/ditutup tanpa realisasi.
 */
interface BudgetProviderInterface
{
    /**
     * Cek ketersediaan anggaran untuk cost center/akun tertentu.
     *
     * @param  array<string, mixed>  $payload
     * @return array{budget: float, committed: float, actual: float, available: float, sufficient: bool}
     */
    public function checkAvailability(array $payload): array;

    /**
     * Komit anggaran (reservasi) saat PO diterbitkan.
     *
     * @param  array<string, mixed>  $payload
     */
    public function commit(array $payload): IntegrationResult;

    /**
     * Lepaskan komitmen (PO dibatalkan / ditutup tanpa realisasi).
     *
     * @param  array<string, mixed>  $payload
     */
    public function release(array $payload): IntegrationResult;
}
