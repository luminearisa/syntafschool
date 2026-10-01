<?php

namespace App\Modules\Bk\Models;

use App\Modules\Bk\Domain\Enums\RetentionEntityType;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kebijakan retensi data.
 *
 * Masa simpan tidak pernah ditulis di kode: kebijakan tersimpan sebagai data
 * berversi, dengan dasar hukum dan kebutuhan persetujuan pemusnahan.
 *
 * Tabel: `bk_retention_policies`
 */
class BkRetentionPolicy extends BkModel
{
    protected $table = 'bk_retention_policies';

    use BelongsToSchool;

    protected $casts = [
        'requires_approval_for_disposal' => 'boolean',
        'is_active' => 'boolean',
        'entity_type' => \App\Modules\Bk\Domain\Enums\RetentionEntityType::class,
    ];

    public function actions(): HasMany
    {
        return $this->hasMany(BkRetentionAction::class, 'policy_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForEntity(Builder $query, RetentionEntityType $entity): Builder
    {
        return $query->where('entity_type', $entity->value);
    }
}
