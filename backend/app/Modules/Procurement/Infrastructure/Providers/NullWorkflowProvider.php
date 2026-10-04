<?php

namespace App\Modules\Procurement\Infrastructure\Providers;

use App\Modules\Procurement\Domain\Contracts\WorkflowProviderInterface;
use Illuminate\Support\Str;

/**
 * Implementasi bawaan bila Workflow Engine belum terpasang.
 *
 * Mengembalikan identitas alur internal (`local-*`) sehingga modul Procurement
 * tetap dapat menjalankan persetujuannya sendiri lewat endpoint approval
 * bawaan. Ketika Workflow Engine terpasang, cukup ganti binding ke adapter yang
 * memanggil `WorkflowProviderInterface` milik modul Workflow.
 */
class NullWorkflowProvider implements WorkflowProviderInterface
{
    public function start(string $workflow, array $payload): array
    {
        return [
            'instance_id' => 'local-'.$workflow.'-'.Str::uuid()->toString(),
            'status' => 'PENDING',
            'steps' => [],
            'engine' => 'internal',
        ];
    }

    public function status(string $instanceId): ?array
    {
        return null;
    }
}
