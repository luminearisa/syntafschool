<?php

namespace App\Modules\Workflow\Console;

use App\Modules\Workflow\Application\Services\DelegationService;
use App\Modules\Workflow\Application\Services\EscalationService;
use Illuminate\Console\Command;

/**
 * `workflow:check-sla` — jantung operasional harian (§40, §69).
 *
 * Idempoten: mengirim pengingat, menandai "segera jatuh tempo", mencatat
 * keterlambatan, menjalankan eskalasi sesuai konfigurasi, sekaligus
 * mengaktifkan delegasi terjadwal dan menutup yang berakhir. Aman dijalankan
 * setiap 5 menit oleh scheduler maupun berulang secara manual dari layar admin.
 */
class WorkflowCheckSlaCommand extends Command
{
    protected $signature = 'workflow:check-sla {--limit=500 : Jumlah catatan SLA yang disapu}';

    protected $description = 'Sapu SLA workflow: pengingat, keterlambatan, eskalasi, dan delegasi terjadwal';

    public function handle(EscalationService $escalations, DelegationService $delegations): int
    {
        $limit = (int) $this->option('limit');

        $stats = $escalations->sweep($limit);
        $delegationStats = $delegations->sweep();

        $this->table(
            ['Pengingat', 'Segera jatuh tempo', 'Terlambat', 'Eskalasi', 'Delegasi aktif', 'Delegasi berakhir'],
            [[
                $stats['reminded'],
                $stats['due_soon'],
                $stats['overdue'],
                $stats['escalated'],
                $delegationStats['activated'],
                $delegationStats['expired'],
            ]],
        );

        $this->info('Sapuan SLA selesai.');

        return self::SUCCESS;
    }
}
