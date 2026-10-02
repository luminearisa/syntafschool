<?php

namespace App\Modules\Hrd\Infrastructure\Workflow;

use App\Models\User;
use App\Modules\Workflow\Application\Services\WorkflowInstanceService;
use App\Modules\Workflow\Domain\Contracts\WorkflowTriggerInterface;
use App\Modules\Workflow\Models\WorkflowInstance;

/**
 * Trigger persetujuan HRD pada Workflow Engine (§33, §55, §66).
 *
 * HRD tidak memiliki mesin persetujuan sendiri: kelas ini menerjemahkan subjek SDM
 * (transaksi, permintaan keluar) menjadi instans workflow pada engine yang sudah
 * ada. Bila definisi workflow belum diterbitkan di engine, kegagalannya
 * dikembalikan sebagai pesan — bukan dibiarkan menggantung — dan transaksi tetap
 * tersimpan untuk disetujui manual oleh HR berizin.
 */
class HrdApprovalTrigger implements WorkflowTriggerInterface
{
    public function __construct(private readonly WorkflowInstanceService $instances) {}

    public function key(): string
    {
        return (string) config('hrd.workflow_trigger_key', 'hrd.hrd_transaction.approval');
    }

    public function definitionCode(): string
    {
        return (string) config('hrd.workflows.transaction', 'HRD_TRANSACTION_APPROVAL');
    }

    public function start(string|int $subjectId, array $payload = [], ?User $actor = null, bool $autoSubmit = true): WorkflowInstance
    {
        $actor ??= User::query()->firstOrFail();

        $instance = $this->instances->create([
            'definition_code' => $payload['definition_code'] ?? $this->definitionCode(),
            'subject_type' => $payload['subject_type'] ?? 'hrd_transaction',
            'subject_id' => (string) $subjectId,
            'subject_reference' => $payload['transaction_number'] ?? null,
            'title' => ($payload['title'] ?? 'Persetujuan SDM').' — '.($payload['employee_name'] ?? $subjectId),
            'priority' => strtoupper((string) ($payload['priority'] ?? 'NORMAL')),
            'context' => $payload,
        ], $actor);

        return $autoSubmit ? $this->instances->submit($instance, $actor) : $instance;
    }
}
