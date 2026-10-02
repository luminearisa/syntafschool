<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Baris tugas pada template onboarding (§38).
 *
 * `due_offset_days` relatif terhadap hari pertama kerja — negatif berarti
 * "sebelum hari pertama" (pre-boarding), positif berarti sesudahnya. Dengan ini
 * onboarding dan pre-boarding memakai satu mesin yang sama.
 */
class HrOnboardingTemplateTask extends HrdModel
{
    protected $casts = [
        'due_offset_days' => 'integer',
        'is_mandatory' => 'boolean',
        'requires_attachment' => 'boolean',
        'blocks_activation' => 'boolean',
        'depends_on' => 'array',
        'sort_order' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(HrOnboardingTemplate::class, 'template_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(HrOnboardingTask::class, 'template_task_id');
    }
}
