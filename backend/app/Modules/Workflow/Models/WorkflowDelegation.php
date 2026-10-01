<?php

namespace App\Modules\Workflow\Models;

use App\Models\User;
use App\Modules\Workflow\Domain\Enums\DelegationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delegasi kewenangan approver (§8, §39).
 *
 * `scope` mendefinisikan batas delegasi: daftar kode definisi, modul, node,
 * atau rentang nominal. Delegasi **tidak** menghapus identitas delegator —
 * penugasan hasil delegasi tetap menyimpan `original_approver_id`.
 *
 * @property DelegationStatus $status
 */
class WorkflowDelegation extends WorkflowModel
{
    protected $table = 'workflow_delegations';

    protected $casts = [
        'scope' => 'array',
        'status' => DelegationStatus::class,
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'activated_at' => 'datetime',
        'revoked_at' => 'datetime',
        'notify_delegator' => 'boolean',
    ];

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn('status', [DelegationStatus::ACTIVE->value, DelegationStatus::PENDING->value])
            ->where('start_at', '<=', now())
            ->where('end_at', '>=', now());
    }

    public function isActiveNow(): bool
    {
        return $this->status === DelegationStatus::ACTIVE
            && $this->start_at->isPast()
            && $this->end_at->isFuture();
    }

    /**
     * Apakah delegasi mencakup sebuah request.
     *
     * Scope yang kosong berarti "semua yang boleh didelegasikan" — pembatasan
     * tetap berlaku dari kebijakan definition (`allow_delegation`) dan izin.
     */
    public function coversDefinition(?string $definitionCode, ?string $module = null, ?string $nodeKey = null, mixed $amount = null): bool
    {
        $scope = $this->scope ?? [];

        if ($scope === [] || $scope === null) {
            return true;
        }

        $definitions = (array) ($scope['definitions'] ?? []);
        $modules = (array) ($scope['modules'] ?? []);
        $nodes = (array) ($scope['nodes'] ?? []);

        if ($definitions !== [] && ($definitionCode === null || ! in_array($definitionCode, $definitions, true))) {
            return false;
        }

        if ($modules !== [] && ($module === null || ! in_array($module, $modules, true))) {
            return false;
        }

        if ($nodes !== [] && ($nodeKey === null || ! in_array($nodeKey, $nodes, true))) {
            return false;
        }

        $min = $scope['amount_min'] ?? null;
        $max = $scope['amount_max'] ?? null;

        if ($amount !== null && ($min !== null || $max !== null)) {
            $value = (float) $amount;

            if ($min !== null && $value < (float) $min) {
                return false;
            }

            if ($max !== null && $value > (float) $max) {
                return false;
            }
        }

        return true;
    }
}
