<?php

namespace App\Modules\Workflow\Domain\Support;

use App\Models\User;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowStepInstance;
use App\Modules\Workflow\Models\WorkflowVersion;

/**
 * Semua yang boleh diketahui sebuah aksi otomatis (§43).
 *
 * Aksi menerima instance + snapshot konteks, bukan model bisnis. Untuk
 * mengubah entitas bisnis, aksi memanggil layanan modulnya sendiri
 * (mis. `FinancePurchaseOrderService`) lewat alias yang didaftarkan modul.
 */
final class WorkflowActionContext
{
    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly WorkflowContext $context,
        public readonly string $hook,
        public readonly ?WorkflowStepInstance $stepInstance = null,
        public readonly ?User $actor = null,
        public readonly ?WorkflowDefinition $definition = null,
        public readonly ?WorkflowVersion $version = null,
    ) {
    }

    public function instanceCode(): string
    {
        return (string) $this->instance->code;
    }

    /** @return array<string, mixed> */
    public function subject(): array
    {
        return [
            'type' => $this->instance->subject_type,
            'id' => $this->instance->subject_id,
            'reference' => $this->instance->subject_reference,
        ];
    }

    public function isApproved(): bool
    {
        return $this->hook === 'approved';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'instance_id' => $this->instance->id,
            'instance_code' => $this->instance->code,
            'hook' => $this->hook,
            'step_key' => $this->stepInstance?->node_key,
            'subject' => $this->subject(),
            'context' => $this->context->toArray(),
            'actor_id' => $this->actor?->id,
        ];
    }
}
