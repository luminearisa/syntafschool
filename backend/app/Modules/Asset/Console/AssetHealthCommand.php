<?php

namespace App\Modules\Asset\Console;

use App\Modules\Asset\Application\Services\AssetKpiService;
use App\Modules\Asset\Models\AssetIntegrationOutbox;
use Illuminate\Console\Command;

class AssetHealthCommand extends Command
{
    protected $signature = 'asset:health';

    protected $description = 'Ringkasan kesehatan operasional modul Asset & Sarpras.';

    public function handle(AssetKpiService $kpi): int
    {
        $assets = $kpi->assetDashboard();
        $maintenance = $kpi->maintenanceDashboard();
        $facilities = $kpi->facilityDashboard();
        $opname = $kpi->stockOpnameSummary();

        $this->table(['Ukuran', 'Nilai'], [
            ['Total aset', $assets['total_assets']],
            ['Aset aktif', $assets['active_assets']],
            ['Dalam perawatan', $assets['under_maintenance']],
            ['Rusak', $assets['damaged']],
            ['Hilang', $assets['lost']],
            ['Dipinjam', $assets['on_loan']],
            ['Garansi berakhir ≤90 hari', $assets['warranty_expiring_90d']],
            ['Permintaan terbuka', $maintenance['open_requests']],
            ['Work order terlambat', $maintenance['overdue_work_orders']],
            ['Biaya pemeliharaan', $maintenance['maintenance_cost_total']],
            ['Fasilitas', $facilities['total_facilities'].' (aktif '.$facilities['active_facilities'].')'],
            ['Booking terbuka', $facilities['bookings_open']],
            ['Selisih opname tertunda', $opname['variance_pending']],
            ['Outbox PENDING', AssetIntegrationOutbox::query()->where('status', 'PENDING')->count()],
            ['Outbox FAILED', AssetIntegrationOutbox::query()->where('status', 'FAILED')->count()],
        ]);

        return self::SUCCESS;
    }
}
