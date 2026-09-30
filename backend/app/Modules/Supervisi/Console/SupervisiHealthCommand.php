<?php

namespace App\Modules\Supervisi\Console;

use App\Modules\Supervisi\Application\Services\SupervisiHealthService;
use Illuminate\Console\Command;

/**
 * Pemeriksaan kesehatan modul supervisi (Fase 5).
 *
 * Dipakai berkala (mis. mingguan oleh admin) atau sebelum rapat supervisi:
 * menampilkan temuan integritas beserta saran perbaikan. `--fail-on-issue`
 * membuat perintah mengembalikan kode keluar ≠ 0 sehingga dapat dipakai di
 * pemantauan otomatis.
 */
class SupervisiHealthCommand extends Command
{
    protected $signature = 'supervisi:health
        {--fail-on-issue : Keluar dengan kode 1 bila ada temuan}
        {--json : Cetak hasil sebagai JSON}';

    protected $description = 'Periksa kesehatan & integritas data supervisi';

    public function handle(SupervisiHealthService $health): int
    {
        $report = $health->report();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Pemeriksaan', 'Status', 'Jumlah', 'Saran'], collect($report['checks'])->map(fn (array $check) => [
                $check['label'],
                strtoupper($check['status']),
                $check['count'],
                $check['suggestion'] ?? '—',
            ])->all());

            $this->newLine();
            $this->line(sprintf(
                'Status: %s · %d diperiksa · %d perlu perhatian · %d kegiatan terbuka · %d butir RTL terlambat',
                strtoupper($report['status']),
                $report['summary']['checks'],
                $report['summary']['needs_attention'],
                $health->openVisits(),
                $health->overdueActionItems(),
            ));

            foreach ($report['checks'] as $check) {
                if ($check['count'] > 0 && $check['samples'] !== []) {
                    $this->line(sprintf('  · %s: %s', $check['label'], implode(', ', array_map('strval', $check['samples']))));
                }
            }

            $this->info(match ($report['status']) {
                SupervisiHealthService::CRITICAL => 'Ada temuan kritis: angka supervisi belum dapat dipercaya sebelum diperbaiki.',
                SupervisiHealthService::WARNING => 'Ada temuan yang perlu ditindaklanjuti, tetapi modul tetap dapat dipakai.',
                default => 'Tidak ada temuan. Data supervisi sehat.',
            });
        }

        if ($this->option('fail-on-issue') && $report['summary']['needs_attention'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
