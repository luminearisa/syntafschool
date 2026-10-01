<?php

namespace App\Modules\Workflow\Infrastructure\Actions;

use App\Modules\Workflow\Domain\Contracts\WorkflowActionInterface;
use App\Modules\Workflow\Domain\Support\WorkflowActionContext;
use App\Modules\Workflow\Events\WorkflowDomainEvent;
use App\Modules\Workflow\Models\WorkflowEvent;

/**
 * Aksi bawaan: publikasikan peristiwa domain (§43, §44).
 *
 * ```json
 * { "handler": "event.publish",
 *   "params": { "name": "PurchaseRequestApproved", "payload": { "module": "finance" } } }
 * ```
 *
 * Modul ERP mendengarkan peristiwa ini dengan listener biasa — engine tidak
 * perlu tahu apa yang terjadi setelahnya.
 */
final class PublishEventAction implements WorkflowActionInterface
{
    public function alias(): string
    {
        return 'event.publish';
    }

    public function description(): string
    {
        return 'Publikasikan peristiwa domain (nama bebas) untuk didengarkan modul ERP.';
    }

    public function execute(WorkflowActionContext $context, array $params = []): array
    {
        $name = (string) ($params['name'] ?? ('Workflow'.$context->hook));

        WorkflowEvent::query()->create([
            'instance_id' => $context->instance->id,
            'definition_id' => $context->instance->definition_id,
            'step_instance_id' => $context->stepInstance?->id,
            'type' => 'domain.'.$name,
            'actor_id' => $context->actor?->id,
            'payload' => (array) ($params['payload'] ?? []),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $event = new WorkflowDomainEvent(
            name: $name,
            instance: $context->instance,
            payload: (array) ($params['payload'] ?? []),
            hook: $context->hook,
        );

        event($event);

        return ['name' => $name];
    }
}
