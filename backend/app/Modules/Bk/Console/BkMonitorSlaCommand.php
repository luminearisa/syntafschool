<?php

namespace App\Modules\Bk\Console;

use App\Modules\Bk\Application\Services\BkSlaService;
use Illuminate\Console\Command;

/**
 * Memantau SLA: peringatan menjelang tenggat, penandaan keterlampauan, dan
 * eskalasi berjenjang. Aman diulang — peringatan hanya dikirim sekali per baris.
 */
class BkMonitorSlaCommand extends Command
{
    protected $signature = 'bk:monitor-sla';

    protected $description = 'Pantau SLA BK, kirim peringatan, tandai keterlampauan, dan eskalasi bila perlu.';

    public function handle(BkSlaService $sla): int
    {
        $result = $sla->monitor();

        $this->info(sprintf(
            'SLA: %d peringatan, %d keterlampauan, %d eskalasi.',
            $result['warned'],
            $result['breached'],
            $result['escalated'],
        ));

        return self::SUCCESS;
    }
}
