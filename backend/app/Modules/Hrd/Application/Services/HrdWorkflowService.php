<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jembatan ke Workflow & Approval Engine (§33, §55, §66, §87).
 *
 * HRD **tidak** membuat mesin persetujuan sendiri. Ia memakai engine yang sudah
 * ada di ERP dan mendaftarkan titik ekstensinya (trigger, context provider, aksi)
 * lewat `WorkflowRegistrar` di service provider modul.
 *
 * Bila engine belum terpasang, HRD tetap bekerja: transaksi tersimpan dengan
 * status persetujuan `null` dan disetujui secara manual oleh HR berizin
 * `hr.lifecycle.approve` — jejak persetujuannya tetap tercatat di `hr_transactions`
 * (siapa, kapan, catatan keputusan). Tidak ada proses yang bergantung pada
 * keberadaan modul lain untuk dapat diselesaikan.
 */
class HrdWorkflowService
{
    public function __construct(private readonly WorkflowRegistrar $registrar) {}

    public function isEngineAvailable(): bool
    {
        return class_exists(WorkflowRegistrar::class) && Schema::hasTable('workflow_instances');
    }

    /**
     * Mulai proses persetujuan sebuah subjek SDM.
     *
     * @param  array<string, mixed>  $payload
     * @return array{started: bool, instance_id: int|null, status: string|null, error: string|null}
     */
    public function startApproval(string $definitionCode, string $subjectType, int $subjectId, array $payload = [], ?User $actor = null): array
    {
        if (! $this->isEngineAvailable()) {
            return ['started' => false, 'instance_id' => null, 'status' => null, 'error' => 'workflow_engine_unavailable'];
        }

        $trigger = $this->registrar->trigger('hrd.'.$subjectType.'.approval');

        if ($trigger === null) {
            return ['started' => false, 'instance_id' => null, 'status' => null, 'error' => 'workflow_trigger_missing'];
        }

        try {
            $instance = $trigger->start($subjectId, ['definition_code' => $definitionCode] + $payload, $actor);

            return [
                'started' => true,
                'instance_id' => (int) $instance->getKey(),
                'status' => (string) ($instance->status->value ?? $instance->status ?? 'submitted'),
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'started' => false,
                'instance_id' => null,
                'status' => null,
                'error' => mb_substr($exception->getMessage(), 0, 300),
            ];
        }
    }

    /** Status persetujuan terkini sebuah subjek, bila engine terpasang. */
    public function approvalStatus(string $subjectType, int $subjectId): ?string
    {
        if (! Schema::hasTable('workflow_instances')) {
            return null;
        }

        $status = DB::table('workflow_instances')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderByDesc('id')
            ->value('status');

        return $status === null ? null : (string) $status;
    }

    public function instanceIdFor(string $subjectType, int $subjectId): ?int
    {
        if (! Schema::hasTable('workflow_instances')) {
            return null;
        }

        $id = DB::table('workflow_instances')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Terjemahkan keputusan engine menjadi status domain HRD.
     *
     * `approved`/`completed` → disetujui; `rejected` → ditolak; selain itu tetap
     * berjalan (masih menunggu approver berikutnya).
     */
    public function mapDecision(string $workflowStatus): ?string
    {
        return match ($workflowStatus) {
            'approved', 'completed' => 'approved',
            'rejected', 'cancelled' => 'rejected',
            default => null,
        };
    }
}
