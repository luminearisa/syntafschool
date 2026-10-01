<?php

namespace App\Modules\Workflow\Events;

use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Peristiwa domain yang dipublikasikan engine (§44).
 *
 * Modul ERP mendengarkan ini untuk bereaksi tanpa engine perlu memanggil mereka
 * langsung — mis. `PurchaseRequestApproved` → buat Purchase Order. Nama
 * peristiwa ditentukan definisi workflow, bukan engine.
 */
class WorkflowDomainEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $name,
        public readonly WorkflowInstance $instance,
        public readonly array $payload = [],
        public readonly string $hook = 'event',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'hook' => $this->hook,
            'instance_code' => $this->instance->code,
            'subject_type' => $this->instance->subject_type,
            'subject_id' => $this->instance->subject_id,
            'payload' => $this->payload,
        ];
    }
}
