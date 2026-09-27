<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceBillingService;
use App\Services\Finance\FinanceCollectionService;
use App\Services\Finance\ReRegistrationService;
use Illuminate\Console\Command;

/**
 * Pemeliharaan harian Keuangan.
 *
 * Status `overdue` dan `expired` tidak tercapai tanpa perintah ini: invoice yang
 * tak terbayar akan terbaca `issued` selamanya, dan daftar ulang lewat jatuh
 * tempo tak pernah berpindah status. Transisi tidak ditulis ulang di sini —
 * jalur service yang sama dengan aksi petugas dipakai, sehingga pengingatnya
 * (santri + wali) ikut terkirim dan idempoten: dokumen yang sama tidak
 * dinotifikasi dua kali walau perintah berjalan berulang.
 *
 * Tangga pengingat (§7, §8) ikut pada kesempatan yang sama karena ia membaca
 * tanggal yang sama: tanpa scheduler, H-7/H-3/H-1/H+1/H+7 hanya hidup di kepala
 * bendahara. Tangga itu dipakai dua kali — satu untuk jatuh tempo invoice, satu
 * untuk jatuh tempo tiap angsuran rencana pembayaran — karena keduanya adalah
 * pengingat dengan irama yang sama tetapi tanggal yang berbeda.
 * Batas waktu pembayaran gateway punya perintah sendiri karena jam kanal
 * berdetak per jam, bukan per hari (§7).
 */
class FinanceDailyMaintenanceCommand extends Command
{
    protected $signature = 'finance:daily-maintenance';

    protected $description = 'Tandai tagihan lewat jatuh tempo, kirim pengingat tangga, dan kedaluwarsakan daftar ulang yang lewat batas';

    public function handle(
        FinanceBillingService $billing,
        FinanceCollectionService $collection,
        ReRegistrationService $reRegistrations,
    ): int {
        $overdue = $billing->markOverdue();
        $reminders = $collection->sendReminders();
        $installments = $collection->sendInstallmentReminders();
        $expired = $reRegistrations->markExpired();

        $this->info("Billing kedaluwarsa: {$overdue['billings']}");
        $this->info("Invoice kedaluwarsa: {$overdue['invoices']}");
        $this->info($reminders['enabled']
            ? 'Pengingat tangga ('.implode(', ', $reminders['offsets'])."): {$reminders['sent']} terkirim dari {$reminders['checked']} tagihan terbuka"
            : 'Pengingat tangga: nonaktif lewat Settings (kunci invoice_reminders_enabled)');
        // Dua baris, satu tangga: angsuran punya tanggal jatuh temponya sendiri,
        // dan meleburnya ke baris tagihan akan membuat "berapa yang terkirim"
        // tidak bisa dipercaya per jenis dokumen.
        $this->info($installments['enabled']
            ? "Pengingat angsuran: {$installments['sent']} terkirim dari {$installments['checked']} angsuran jatuh tempo"
            : 'Pengingat angsuran: nonaktif lewat Settings (kunci invoice_reminders_enabled)');
        $this->info("Daftar ulang kedaluwarsa: {$expired}");

        return self::SUCCESS;
    }
}
