<?php

namespace App\Modules\Bk\Console;

use App\Modules\Bk\Application\Services\BkEarlyWarningEngine;
use Illuminate\Console\Command;

/**
 * Menjalankan mesin aturan peringatan dini untuk seluruh siswa.
 *
 * Aman dijalankan berkali-kali pada hari yang sama: kunci idempotensi pada
 * peristiwa dan penanda membuat tindakan yang sama tidak berulang.
 */
class BkRunEarlyWarningCommand extends Command
{
    protected $signature = 'bk:run-early-warning {--limit= : Batas jumlah siswa yang dievaluasi}';

    protected $description = 'Evaluasi aturan peringatan dini dan buat penanda dukungan yang diperlukan.';

    public function handle(BkEarlyWarningEngine $engine): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $summary = $engine->evaluateBatch(limit: $limit);

        $this->info(sprintf(
            'Evaluasi selesai: %d dievaluasi, %d ditindak, %d tidak cocok, %d ditahan jeda.',
            $summary['evaluated'],
            $summary['actioned'],
            $summary['skipped'],
            $summary['cooldown'],
        ));

        return self::SUCCESS;
    }
}
