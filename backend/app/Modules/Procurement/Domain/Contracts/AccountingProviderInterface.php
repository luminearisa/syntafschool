<?php

namespace App\Modules\Procurement\Domain\Contracts;

use App\Modules\Procurement\Domain\Support\IntegrationResult;

/**
 * Accounting — sumber kebenaran transaksi keuangan (AP, jurnal, GL, pembayaran).
 *
 * Modul Procurement **tidak** membuat jurnal maupun payable sendiri. Setelah
 * invoice lolos matching, ia menyerahkan payload ke kontrak ini. Implementasi
 * bawaan (`NullAccountingProvider`) hanya mencatat niat pada outbox integrasi
 * sehingga tidak hilang bila modul Accounting belum terpasang.
 */
interface AccountingProviderInterface
{
    /**
     * Buat utang usaha (accounts payable) dari invoice pengadaan.
     *
     * WAJIB idempoten terhadap `idempotency_key` di dalam payload: pemanggilan
     * ulang dengan kunci yang sama tidak boleh menghasilkan posting ganda.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createPayable(array $payload): IntegrationResult;

    /**
     * Status invoice menurut Accounting (mis. POSTED / REJECTED).
     *
     * @return array<string, mixed>|null
     */
    public function getInvoiceStatus(string $reference): ?array;

    /**
     * Status pembayaran menurut Accounting (UNPAID / PARTIAL / PAID / OVERDUE).
     * Procurement hanya menampilkan; tidak menyimpan ledger pembayaran sendiri.
     *
     * @return array<string, mixed>|null
     */
    public function getPaymentStatus(string $reference): ?array;
}
