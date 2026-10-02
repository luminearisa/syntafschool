<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Titik integrasi ke luar modul HRD: Aturan pajak.
 *
 * Bila modul tujuan belum terpasang, implementasi bawaan mengembalikan jawaban
 * "belum tersedia" dan peristiwanya tetap tercatat di outbox — bukan membuat
 * modul HRD gagal. Pola ini sama dengan Phase 1 dan menjaga batas modul.
 */
interface PayrollTaxRuleProviderInterface
{
    /**
     * Versi tabel aturan yang berlaku.
     */
    public function version(): string;
    /**
     * Menghitung pajak (PPh 21 dan potongan wajib lain).
     *
     * @return array<string, mixed>
     */
    public function calculate(array $taxableBase, array $context): array;
    /**
     * Aturan iuran (BPJS Kesehatan, BPJS Ketenagakerjaan).
     *
     * @return array<string, mixed>
     */
    public function contributionRules(array $context): array;
}
