<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Modules\Supervisi\Domain\Enums\ActionItemStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu langkah tindak lanjut (SMART: pernyataan, dukungan, tenggat, bukti). */
class SupActionItem extends BaseModel
{
    protected $table = 'sup_action_items';

    protected $casts = [
        'status' => ActionItemStatus::class,
        'due_date' => 'date',
        'progress_percent' => 'integer',
        'completed_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SupActionPlan::class, 'action_plan_id');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(SupActionProgress::class, 'action_item_id')->orderByDesc('reported_at');
    }
}
