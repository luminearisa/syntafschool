<?php

namespace App\Console\Commands;

use App\Services\Finance\FinancePaymentService;
use Illuminate\Console\Command;

/**
 * Kedaluwarsakan pembayaran kanal yang lewat batas waktunya (§7).
 *
 * Charge gateway/VA punya masa berlaku di sisi provider. Bila masa itu lewat dan
 * tidak ada notifikasi `paid` yang masuk, barisnya tetap `pending`: portal dan
 * penagihan membacanya sebagai "sedang dibayar", wali tidak bisa mengulang lewat
 * kanal yang sama, dan tagihannya tidak pernah naik ke tangga berikutnya.
 *
 * Hanya baris ber-`expires_at` yang diperiksa, jadi pembayaran transfer manual —
 * yang menunggu keputusan petugas, bukan jam — tidak pernah tersentuh. Transisi
 * per baris lewat `FinancePaymentService::expire()` (mesin status + jejak
 * verifikasi + pengingat), sehingga menjalankan perintah ini berulang aman: baris
 * yang sudah selesai ditolak transisinya, bukan digandakan.
 *
 * Dijadwal lebih sering daripada job harian karena satuan waktunya jam: channel
 * yang mati harus lepas dari antrean pada jam berikutnya, bukan besok pagi.
 */
class FinanceExpirePaymentsCommand extends Command
{
    protected $signature = 'finance:expire-payments';

    protected $description = 'Tutup pembayaran gateway/VA yang melewati batas waktunya tanpa pernah dibayar';

    public function handle(FinancePaymentService $payments): int
    {
        $result = $payments->expireStale();

        $this->info("Pembayaran diperiksa: {$result['scanned']}");
        $this->info("Pembayaran kedaluwarsa: {$result['expired']}");

        return self::SUCCESS;
    }
}
