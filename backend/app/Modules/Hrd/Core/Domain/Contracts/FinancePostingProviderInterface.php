<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Titik integrasi ke luar modul HRD: Finance & Accounting.
 *
 * Bila modul tujuan belum terpasang, implementasi bawaan mengembalikan jawaban
 * "belum tersedia" dan peristiwanya tetap tercatat di outbox — bukan membuat
 * modul HRD gagal. Pola ini sama dengan Phase 1 dan menjaga batas modul.
 */
interface FinancePostingProviderInterface
{
    /**
     * Mengirim jurnal (payroll, pelatihan, rekrutmen) ke Finance.
     *
     * @return array<string, mixed>
     */
    public function postJournal(array $journal, int $actorUserId): array;
    /**
     * Memeriksa ketersediaan anggaran sebelum komitmen biaya.
     *
     * @return array<string, mixed>
     */
    public function budgetAvailable(array $query): array;
    /**
     * Memetakan organisasi/departemen ke pusat biaya.
     */
    public function costCenterFor(int $organizationId, ?int $departmentId = null): ?string;
}
