<?php

namespace App\Modules\UKS\Console;

use App\Modules\UKS\Application\Services\UksHealthService;
use Illuminate\Console\Command;

/** `php artisan uks:health` — pemeriksaan integritas data UKS, hanya membaca. */
class UksHealthCommand extends Command
{
    protected $signature = 'uks:health {--json : Keluaran JSON} {--fail-on-issue : Keluar dengan kode gagal bila ada temuan}';

    protected $description = 'Memeriksa integritas data modul UKS (kunjungan, stok obat, surat, rujukan).';

    public function handle(UksHealthService $health): int
    {
        $report = $health->report();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('Status UKS: '.strtoupper($report['status']));
            $this->line("Kunjungan hari ini: {$report['visits_today']} | Masih terbuka: {$report['open_visits']} | Perlu perhatian: {$report['needs_attention']}");

            $rows = array_map(fn (array $c): array => [
                $c['severity'], $c['count'] > 0 ? 'PERLU PERHATIAN' : 'OK', $c['count'], $c['label'],
            ], $report['checks']);
            $this->table(['Tingkat', 'Status', 'Jumlah', 'Pemeriksaan'], $rows);

            foreach ($report['checks'] as $check) {
                if ($check['count'] > 0) {
                    $this->warn($check['label'].': '.$check['suggestion'].' Contoh: '.implode(', ', array_slice($check['sample'], 0, 5)));
                }
            }
        }

        if ($this->option('fail-on-issue') && $report['needs_attention'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
