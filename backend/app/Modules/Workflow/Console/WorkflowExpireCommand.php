<?php

namespace App\Modules\Workflow\Console;

use App\Modules\Workflow\Application\Services\WorkflowInstanceService;
use Illuminate\Console\Command;

/**
 * `workflow:expire` — menutup request yang sudah tidak mungkin dilanjutkan.
 *
 * Dua hal yang ditutup, keduanya tanpa menghapus apa pun: request RETURNED yang
 * tidak direvisi dalam batas hari, dan draf yang tidak pernah dikirim. Keputusan
 * lama tetap ada; hanya statusnya menjadi EXPIRED beserta alasannya.
 */
class WorkflowExpireCommand extends Command
{
    protected $signature = 'workflow:expire
        {--returned-days= : Batas hari untuk request RETURNED (default dari konfigurasi)}
        {--draft-days= : Batas hari untuk draf}';

    protected $description = 'Tandai request workflow yang kedaluwarsa (RETURNED tanpa revisi, draf terlantar)';

    public function handle(WorkflowInstanceService $instances): int
    {
        $returnedDays = (int) ($this->option('returned-days') ?: config('workflow.sla.expire_returned_after_days', 30));
        $draftDays = (int) ($this->option('draft-days') ?: config('workflow.sla.expire_drafts_after_days', 30));

        $stats = $instances->expireStale($returnedDays, $draftDays);

        $this->table(
            ['Jenis', 'Jumlah', 'Batas hari'],
            [
                ['RETURNED tanpa revisi', $stats['returned'], $returnedDays],
                ['Draf terlantar', $stats['drafts'], $draftDays],
            ],
        );

        return self::SUCCESS;
    }
}
