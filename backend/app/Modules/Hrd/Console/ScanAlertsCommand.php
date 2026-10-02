<?php

namespace App\Modules\Hrd\Console;

use App\Modules\Hrd\Core\Services\AlertService;
use App\Modules\Hrd\Core\Support\HrdAutomationRunner;
use Illuminate\Console\Command;

/** Pemindaian peringatan: kontrak, sertifikasi, pelatihan, kinerja, payroll. */
class ScanAlertsCommand extends Command
{
    protected $signature = 'hrd:scan-alerts';

    protected $description = 'Memindai kontrak, sertifikasi, pelatihan, tinjauan, sasaran, lembur, presensi, dan payroll.';

    public function handle(AlertService $alerts, HrdAutomationRunner $runner): int
    {
        $run = $runner->run('ALERTS', 'alerts:'.now()->toDateString().':'.now()->format('H'), function () use ($alerts) {
            $counts = $alerts->scanAll();

            return ['processed' => array_sum($counts), 'by_type' => $counts];
        });

        $this->info('Peringatan: '.json_encode($run->summary));

        return self::SUCCESS;
    }
}
