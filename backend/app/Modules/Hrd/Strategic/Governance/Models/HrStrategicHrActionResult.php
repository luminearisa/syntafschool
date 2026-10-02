<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Governance\Models\HrStrategicHrActionItem;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrStrategicHrActionResult extends Model
{
    protected $table = 'hr_strategic_hr_action_results';

    protected $guarded = [];

    protected $casts = [
        'action_id' => 'integer',
        'item_id' => 'integer',
        'metric_code' => 'string',
        'baseline_value' => 'decimal:2',
        'target_value' => 'decimal:2',
        'actual_value' => 'decimal:2',
        'variance' => 'decimal:2',
        'period' => 'string',
        'evidence' => 'array',
        'measured_at' => 'datetime',
        'notes' => 'string',
        'meta' => 'array',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(HrStrategicHrActionItem::class, 'item_id');
    }
}
