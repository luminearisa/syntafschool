<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Models\SupAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Penulis jejak audit modul supervisi.
 *
 * Dipanggil setiap kali instrumen diterbitkan, supervisor didaftarkan, atau
 * penugasan dibuat — agar pertanyaan “siapa mengubah apa dan kapan” selalu
 * dapat dijawab (syarat audit akademik & kepegawaian).
 */
class AuditLogger
{
    public function log(
        string $action,
        Model|string $entity,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?User $actor = null,
        array $context = [],
    ): SupAuditLog {
        $entityType = $entity instanceof Model ? $entity::class : $entity;
        $entityId = $entity instanceof Model ? $entity->getKey() : null;

        return SupAuditLog::query()->create([
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_role' => $actor?->getRoleNames()->first(),
            'before' => $before,
            'after' => $after,
            'context' => $context === [] ? null : $context,
            'reason' => $reason,
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 255) ?: null,
            'correlation_id' => request()?->header('X-Correlation-Id'),
        ]);
    }

    /** Bandingkan atribut model sebelum & sesudah perubahan. */
    public function diff(Model $model): array
    {
        $changed = [];
        foreach ($model->getDirty() as $key => $value) {
            $changed[$key] = [
                'before' => $model->getOriginal($key),
                'after' => $value,
            ];
        }

        return $changed;
    }
}
