<?php

namespace App\Modules\Procurement\Console;

use App\Modules\Procurement\Application\Services\VendorPerformanceService;
use Illuminate\Console\Command;

/** Hitung ulang scorecard kinerja seluruh vendor untuk satu periode (§7). */
class ComputeVendorPerformanceCommand extends Command
{
    protected $signature = 'procurement:vendor-performance {period? : Periode YYYY-MM (default bulan lalu)}';

    protected $description = 'Hitung scorecard kinerja vendor dari data PO/penerimaan/invoice.';

    public function handle(VendorPerformanceService $performance): int
    {
        $period = (string) ($this->argument('period') ?? now()->subMonth()->format('Y-m'));

        $count = $performance->computeAll($period);

        $this->info("Scorecard vendor periode {$period} dihitung untuk {$count} vendor.");

        return self::SUCCESS;
    }
}
