<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceBillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Generate SPP terjadwal (§7).
 *
 * Penerbitan tagihan massal tidak lagi menunggu seseorang sempat membukanya:
 * paket yang dipilih bendahara ditagihkan ke seluruh santri aktif untuk jendela
 * periode yang sedang berjalan (bulanan, triwulan, semester, atau tahun ajaran).
 * Semuanya lewat jalur service yang sama dengan tombol "generate" di layar
 * (`FinanceBillingService::generateBulk()` + `createInvoice()`), jadi idempotensi
 * per (santri, paket, periode), penjaga periode tertutup, jurnal pengakuan, dan
 * pengingat ke wali tetap satu jalur — bukan mesin kedua.
 *
 * Perintah ini boleh dijalankan berulang dalam satu periode: tagihan kedua tidak
 * pernah lahir. Karena itu ia aman dijadwalkan harian dan tidak perlu presisi
 * pada hari eksekusinya.
 */
class FinanceGenerateSppCommand extends Command
{
    protected $signature = 'finance:generate-spp {--date= : Tanggal acuan jendela periode (default: hari ini)}';

    protected $description = 'Terbitkan tagihan SPP otomatis untuk periode berjalan sesuai konfigurasi (idempoten per santri/paket/periode)';

    public function handle(FinanceBillingService $billing): int
    {
        $asOf = $this->option('date') !== null
            ? Carbon::parse((string) $this->option('date'))
            : null;

        $report = $billing->generateScheduled($asOf);

        $this->info("Siklus: {$report['cycle']}".($report['period_key'] !== null ? " ({$report['period_key']})" : ''));
        $this->info("Billing terbit: {$report['generated']}");
        $this->info("Billing sudah ada: {$report['skipped']}");
        $this->info("Invoice terbit: {$report['invoices']}");
        $this->info("Invoice sudah ada: {$report['already_invoiced']}");

        if ($report['failed'] > 0) {
            $this->warn("Invoice gagal terbit: {$report['failed']} — {$report['reason']}");
        }

        if ($report['reason'] !== null && $report['failed'] === 0) {
            // Bukan kegagalan sistem: fitur mati, paket belum dipilih, atau
            // kalender akademik belum diisi. Dilaporkan agar diketahui, bukan
            // didiamkan — tagihan yang tidak pernah lahir tidak pernah ditagih.
            $this->warn($report['reason']);

            Log::warning('finance: generate SPP terjadwal tidak menghasilkan tagihan', [
                'cycle' => $report['cycle'],
                'reason' => $report['reason'],
            ]);
        }

        return self::SUCCESS;
    }
}
