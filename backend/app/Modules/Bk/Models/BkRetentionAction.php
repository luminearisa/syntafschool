<?php

namespace App\Modules\Bk\Models;

use App\Models\User;
use App\Modules\Bk\Domain\Enums\RetentionEntityType;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak tindakan retensi.
 *
 * Perjalanan Active lalu Archived lalu Retention Expired lalu Approved Disposal,
 * dan setiap langkah punya barisnya sendiri sehingga pemusnahan dapat diaudit.
 *
 * Tabel: `bk_retention_actions`
 */
class BkRetentionAction extends BkModel
{
    protected $table = 'bk_retention_actions';

    use BelongsToSchool;

    protected $casts = [
        'performed_at' => 'datetime',
        'approved_at' => 'datetime',
        'snapshot' => 'array',
        'entity_type' => \App\Modules\Bk\Domain\Enums\RetentionEntityType::class,
        'action' => \App\Modules\Bk\Domain\Enums\RetentionActionType::class,
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(BkRetentionPolicy::class, 'policy_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeForEntity(Builder $query, RetentionEntityType $entity, int $entityId): Builder
    {
        return $query->where('entity_type', $entity->value)->where('entity_id', $entityId);
    }
}
