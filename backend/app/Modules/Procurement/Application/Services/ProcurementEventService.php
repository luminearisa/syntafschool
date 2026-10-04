<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Events\ProcurementDomainEvent;
use App\Modules\Procurement\Models\EventLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Penyalur peristiwa domain pengadaan (§50).
 *
 * Setiap peristiwa **selalu** dicatat ke `proc_events_log` (outbox) sebelum
 * di-`dispatch`, sehingga integrasi (notifikasi, audit, accounting, inventory,
 * asset, analitik) tidak pernah kehilangan peristiwa walau listener belum
 * terpasang.
 */
class ProcurementEventService
{
    /** @param array<string, mixed> $payload */
    public function dispatch(string $name, Model|string|null $entity = null, array $payload = []): EventLog
    {
        [$type, $id] = $entity instanceof Model
            ? [$entity::class, $entity->getKey()]
            : ($entity !== null ? [$entity, null] : [null, null]);

        $log = EventLog::query()->create([
            'event_name' => $name,
            'entity_type' => $type,
            'entity_id' => $id,
            'payload' => $payload,
            'dispatched_at' => now(),
        ]);

        Event::dispatch(new ProcurementDomainEvent($name, $type, $id, $payload));

        return $log;
    }
}
