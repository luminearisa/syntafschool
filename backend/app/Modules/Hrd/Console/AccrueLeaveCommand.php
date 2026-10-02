<?php

namespace App\Modules\Hrd\Console;

use App\Modules\Hrd\Core\Support\HrdAutomationRunner;
use App\Modules\Hrd\Leave\Services\LeavePolicyService;
use Illuminate\Console\Command;

/** Akrual cuti bulanan — idempoten per periode. */
class AccrueLeaveCommand extends Command
{
    protected $signature = 'hrd:accrue-leave {--period= : Periode YYYY-MM, bawaan bulan berjalan}';

    protected $description = 'Menjalankan akrual cuti untuk seluruh pegawai aktif pada satu periode.';

    public function handle(LeavePolicyService $policies, HrdAutomationRunner $runner): int
    {
        $period = (string) ($this->option('period') ?: now()->format('Y-m'));

        $run = $runner->run('LEAVE_ACCRUAL', 'leave-accrual:'.$period, function () use ($policies, $period) {
            $result = $policies->accrueAll($period);

            return $result + ['processed' => $result['accrued']];
        });

        $this->info(sprintf('Akrual %s: %s (status %s).', $period, json_encode($run->summary), $run->status));

        return self::SUCCESS;
    }
}
