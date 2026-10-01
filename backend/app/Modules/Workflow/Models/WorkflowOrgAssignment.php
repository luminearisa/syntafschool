<?php

namespace App\Modules\Workflow\Models;

use App\Models\User;
use App\Modules\Workflow\Domain\Enums\OrgScopeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Peta cakupan organisasi → pengguna berwenang (lihat `OrgScopeType`).
 *
 * @property OrgScopeType $scope_type
 */
class WorkflowOrgAssignment extends WorkflowModel
{
    protected $table = 'workflow_org_assignments';

    protected $casts = [
        'scope_type' => OrgScopeType::class,
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeActiveAt(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()->toDateString()))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()->toDateString()));
    }
}
