<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Support\LmsActor;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Audit trail LMS (§23).
 *
 * Immutable: baris audit tidak boleh diubah atau dihapus. Penjagaan di sini
 * bersifat aplikatif — tetap disarankan membatasi hak `UPDATE`/`DELETE` pada
 * basis data produksi.
 */
class LmsAuditLog extends BaseModel
{
    protected $table = 'lms_audit_logs';

    public const UPDATED_AT = null;

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'context' => 'array',
        'created_at' => 'datetime',
        'entity_id' => 'integer',
        'course_id' => 'integer',
        'actor_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Baris audit LMS bersifat immutable.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Baris audit LMS tidak boleh dihapus.');
        });
    }

    /** @param Builder<LmsAuditLog> $query */
    public function scopeForEntity(Builder $query, string $type, int $id): Builder
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }

    public function toPayload(): array
    {
        return [
            'id' => (int) $this->id,
            'action' => $this->action,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'course_id' => $this->course_id,
            'actor' => [
                'id' => $this->actor_id,
                'name' => $this->actor_name,
                'role' => $this->actor_role,
            ],
            'before' => $this->before,
            'after' => $this->after,
            'context' => $this->context,
            'ip_address' => $this->ip_address,
            'correlation_id' => $this->correlation_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** Aktor untuk baris audit manual (mis. dari job tanpa HTTP). */
    public static function describeActor(?LmsActor $actor): array
    {
        if ($actor === null) {
            return ['id' => null, 'name' => 'Sistem', 'role' => 'system'];
        }

        return ['id' => $actor->id, 'name' => $actor->name, 'role' => $actor->primaryRole->value];
    }
}
