<?php

namespace App\Modules\Asset\Domain\Contracts;

/**
 * Pengadaan — sumber kebenaran transaksi pengadaan.
 *
 * Modul Asset TIDAK membuat purchase order sendiri. Ia menitipkan permintaan
 * dan menerima kabar penerimaan barang; bila modul Pengadaan tidak terpasang,
 * binding bawaan mencatat niat itu pada outbox integrasi sehingga tidak hilang.
 */
interface ProcurementProviderInterface
{
    /** Ajukan permintaan pengadaan atas nama unit; mengembalikan referensi permintaan. */
    public function request(array $payload): array;

    /** Rincian permintaan/pesanan pengadaan beserta statusnya. */
    public function status(string $reference): ?array;

    /** Baris penerimaan barang (receiving) yang siap menjadi aset. */
    public function receipts(array $filters): array;

    /** Hubungkan aset yang lahir dengan baris penerimaan, agar dapat direkonsiliasi. */
    public function markRecognized(string $reference, array $assetCodes): array;
}
