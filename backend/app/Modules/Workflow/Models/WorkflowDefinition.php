<?php

namespace App\Modules\Workflow\Models;

use App\Models\User;
use App\Modules\Workflow\Domain\Enums\DefinitionStatus;
use App\Modules\Workflow\Domain\Enums\TriggerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Definisi workflow — template yang dipakai bersama seluruh modul ERP (§13).
 *
 * Definisi tidak menyimpan satu pun aturan bisnis: ia hanya menunjuk subjek
 * (`entity_type`), peristiwa pemicunya, dan versi yang sedang aktif.
 *
 * @property DefinitionStatus $status
 * @property TriggerType $trigger_type
 */
class WorkflowDefinition extends WorkflowModel
{
    use SoftDeletes;

    protected $table = 'workflow_definitions';

    protected $casts = [
        'status' => DefinitionStatus::class,
        'trigger_type' => TriggerType::class,
        'allow_self_approval' => 'boolean',
        'is_system' => 'boolean',
        'comment_required_on' => 'array',
        'required_context' => 'array',
        'observers' => 'array',
        'settings' => 'array',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class, 'definition_id')->orderByDesc('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'current_version_id');
    }

    public function activeVersion(): HasOne
    {
        return $this->hasOne(WorkflowVersion::class, 'definition_id')
            ->where('status', 'ACTIVE')
            ->latestOfMany('version');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class, 'definition_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', DefinitionStatus::ACTIVE->value);
    }

    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }

    public function isActive(): bool
    {
        return $this->status === DefinitionStatus::ACTIVE;
    }

    /** Versi yang boleh dipakai untuk request baru. */
    public function startableVersion(): ?WorkflowVersion
    {
        if ($this->isActive() && $this->current_version_id !== null) {
            $version = $this->relationLoaded('currentVersion')
                ? $this->currentVersion
                : $this->currentVersion()->first();

            if ($version !== null && $version->status->canStartRequest()) {
                return $version;
            }
        }

        return $this->versions()->where('status', 'ACTIVE')->orderByDesc('version')->first();
    }
}
