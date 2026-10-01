<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * Keuangan — modul ini **meminta**, bukan menagih sendiri (§62, §97).
 *
 * Tagihan pendaftaran, iuran, peralatan, dan biaya lomba dikirim sebagai
 * permintaan; keputusan besar-kecilnya, diskon, dan penerimaan pembayaran tetap
 * milik modul Finance. Kebijakan siswa yang belum membayar dibaca dari
 * `unpaidPolicy()` — tidak pernah di-hard-code di modul ini.
 */
interface FinanceProviderInterface
{
    /** Ajukan tagihan; kembalikan `['status' => ..., 'reference' => ...]`. */
    public function charge(array $payload): array;

    public function chargeStatus(string $reference): ?array;

    /** Kebijakan bila siswa belum melunasi: ALLOW | WARN | RESTRICT. */
    public function unpaidPolicy(): array;
}
