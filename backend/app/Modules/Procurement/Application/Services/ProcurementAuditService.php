<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Enums\ProcurementAuditAction;
use App\Modules\Procurement\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Jejak audit modul Procurement (§51).
 *
 * Wajib dipanggil pada aksi penting: perubahan vendor, evaluasi penawaran,
 * pemilihan vendor, persetujuan/amandemen PO, penerimaan, perubahan invoice,
 * override matching, handoff accounting, pembatalan, dan override manual.
 */
class ProcurementAuditService
{
    /** @param array<string, mixed> $old @param array<string, mixed> $new */
    public function record(
        ProcurementAuditAction $action,
        Model|string $entity,
        ?array $old = null,
        ?array $new = null,
        ?string $reason = null,
    ): AuditLog {
        [$type, $id] = $entity instanceof Model
            ? [$entity::class, $entity->getKey()]
            : [$entity, null];

        $request = request();

        return AuditLog::query()->create([
            'user_id' => Auth::id(),
            'action' => $action->value,
            'entity_type' => $type,
            'entity_id' => $id,
            'old_values' => $old,
            'new_values' => $new,
            'reason' => $reason,
            'ip' => $request?->ip(),
            'user_agent' => $request !== null ? substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }
}
