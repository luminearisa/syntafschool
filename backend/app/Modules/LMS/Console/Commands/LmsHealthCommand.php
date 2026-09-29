<?php

namespace App\Modules\LMS\Console\Commands;

use App\Modules\LMS\Application\Services\SystemHealthService;
use Illuminate\Console\Command;

/** `php artisan lms:health` — pemeriksaan kesehatan LMS (§42). */
class LmsHealthCommand extends Command
{
    protected $signature = 'lms:health {--json : Keluarkan ringkasan JSON}';

    protected $description = 'Memeriksa kesehatan LMS (basis data, antrean, penyimpanan, integrasi)';

    public function handle(SystemHealthService $health): int
    {
        $report = $health->check();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['status'] === 'UNAVAILABLE' ? self::FAILURE : self::SUCCESS;
        }

        $this->info('LMS health: '.$report['status'].' ('.$report['status_label'].') — mode '.$report['mode']);

        foreach ($report['checks'] as $name => $check) {
            $this->line(sprintf('  %-20s %-12s %s', $name, $check['status'], (string) $check['detail']));
        }

        return $report['status'] === 'UNAVAILABLE' ? self::FAILURE : self::SUCCESS;
    }
}
