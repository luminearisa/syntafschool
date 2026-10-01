<?php

namespace App\Modules\Asset\Domain\Contracts;

/**
 * Keuangan — satu-satunya sumber kebenaran akuntansi.
 *
 * Modul Asset menyediakan data operasional (tanggal perolehan, umur, metode,
 * nilai residu) lalu meminta Keuangan mencatatkan akibatnya. Modul ini tidak
 * menulis jurnal sendiri dan tidak menyimpan buku besar kedua.
 *
 * Setiap metode mengembalikan array berisi `reference` (id jurnal/aset
 * keuangan, bila ada) dan `status` (`DISPATCHED` atau `PENDING`) — `PENDING`
 * berarti niat itu dicatat pada outbox integrasi untuk dikerjakan modul
 * Keuangan, bukan hilang.
 */
interface FinanceProviderInterface
{
    /** Catat perolehan aset (kapitalisasi) beserta nilainya. */
    public function capitalize(array $payload): array;

    /** Susutkan aset untuk satu periode; `period` format YYYY-MM. */
    public function depreciate(int $assetId, string $period, array $context = []): array;

    /** Catat penghapusan/pelepasan beserta laba-rugi yang timbul. */
    public function dispose(array $payload): array;

    /** Catat penilaian ulang/penurunan nilai yang telah disetujui. */
    public function revalue(array $payload): array;

    /** Catat biaya pemeliharaan agar terbebani pada unit yang tepat. */
    public function recordMaintenanceCost(array $payload): array;

    /** Nilai buku terkini menurut Keuangan (null bila belum tercatat). */
    public function bookValue(int $assetId): ?string;
}
