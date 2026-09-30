<?php

namespace App\Modules\Clinic\Console;

use App\Modules\Clinic\Models\ClinicMedicationBatch;
use Illuminate\Console\Command;

/**
 * Tandai batch obat yang sudah kedaluwarsa.
 *
 * Batch kedaluwarsa tidak dihapus — hanya berubah status, sehingga riwayat
 * penerimaan obat tetap utuh untuk diaudit dan obat tidak mungkin diberikan
 * lagi karena `scopeUsable()` menolaknya.
 */
class ClinicExpireBatchesCommand extends Command
{
    protected $signature = 'clinic:expire-batches';

    protected $description = 'Tandai batch obat klinik yang sudah melewati tanggal kedaluwarsa';

    public function handle(): int
    {
        $expired = ClinicMedicationBatch::query()
            ->where('status', 'AVAILABLE')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString())
            ->update(['status' => 'EXPIRED', 'updated_at' => now()]);

        $soon = ClinicMedicationBatch::query()
            ->where('status', 'AVAILABLE')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->whereDate('expiry_date', '<=', now()->addDays((int) config('clinic.expiry_warning_days', 60))->toDateString())
            ->count();

        $this->info("Batch kedaluwarsa ditandai: {$expired}. Akan kedaluwarsa dalam ".config('clinic.expiry_warning_days', 60)." hari: {$soon}.");

        return self::SUCCESS;
    }
}
