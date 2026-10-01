<?php

namespace App\Modules\Bk\Console;

use App\Modules\Bk\Application\Services\BkHealthService;
use Illuminate\Console\Command;

/**
 * Periksa kesehatan modul BK: integrasi, antrean, dan penegakan privasi.
 */
class BkHealthCommand extends Command
{
    protected $signature = 'bk:health';

    protected $description = 'Periksa kesehatan modul Bimbingan & Konseling.';

    public function handle(BkHealthService $health): int
    {
        $report = $health->check();

        $this->table(['Bagian', 'Nilai'], [
            ['Status', $report['status']],
            ['Workflow', $report['integrations']['workflow']['available'] ? 'tersedia' : 'tidak terpasang'],
            ['Modul Activity', $report['integrations']['student_activity']['available'] ? 'tersedia' : 'tidak terpasang'],
            ['Modul Clinic', $report['integrations']['clinic']['available'] ? 'tersedia' : 'tidak terpasang'],
            ['Asrama', $report['integrations']['boarding']['available'] ? 'tersedia' : 'tidak terpasang'],
            ['Referral menunggu', (string) $report['queue']['referrals_waiting']],
            ['Kasus tanpa konselor', (string) $report['queue']['unassigned_cases']],
            ['Tindak lanjut terlambat', (string) $report['queue']['follow_ups_overdue']],
            ['Persetujuan menunggu', (string) $report['queue']['consents_pending']],
        ]);

        return self::SUCCESS;
    }
}
