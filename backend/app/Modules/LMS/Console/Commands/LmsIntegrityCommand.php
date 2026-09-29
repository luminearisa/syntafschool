<?php

namespace App\Modules\LMS\Console\Commands;

use App\Modules\LMS\Application\Services\LmsIntegrityService;
use App\Modules\LMS\Domain\Support\LmsSystemActor;
use Illuminate\Console\Command;

/**
 * `php artisan lms:integrity` — memeriksa integritas data (§24).
 *
 * Perintah ini **tidak** memperbaiki apa pun secara otomatis; ia menampilkan
 * temuan beserta saran perbaikan.
 */
class LmsIntegrityCommand extends Command
{
    protected $signature = 'lms:integrity
        {--scope=all : Lingkup pemeriksaan}
        {--json : Keluarkan hasil JSON}
        {--fail-on-critical : Keluar dengan status gagal bila ada temuan kritis}';

    protected $description = 'Memeriksa integritas data LMS dan menampilkan saran perbaikan';

    public function handle(LmsIntegrityService $integrity): int
    {
        $result = $integrity->run(LmsSystemActor::make(), (string) $this->option('scope'));

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Integritas: '.$result['status'].' — '.$result['issues_found'].' temuan dari '.$result['checks_run'].' pemeriksaan.');

            foreach (array_slice($result['findings'], 0, 25) as $finding) {
                $this->line('  ['.$finding['severity'].'] '.$finding['message']);
                $this->line('        saran: '.$finding['repair']['suggestion']);
            }

            if (count($result['findings']) > 25) {
                $remaining = count($result['findings']) - 25;
                $this->line('  … '.$remaining.' temuan lain tersimpan pada riwayat pemeriksaan.');
            }
        }

        if ($this->option('fail-on-critical') && $result['critical'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
