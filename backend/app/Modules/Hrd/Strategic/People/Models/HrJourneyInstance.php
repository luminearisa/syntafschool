<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\People\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Strategic\People\Models\HrJourneyEvent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrJourneyInstance extends Model
{
    protected $table = 'hr_journey_instances';

    protected $guarded = [];

    protected $casts = [
        'journey_code' => 'string',
        'employee_id' => 'integer',
        'code' => 'string',
        'started_at' => 'datetime',
        'status' => 'string',
        'current_stage_code' => 'string',
        'progress_percent' => 'decimal:2',
        'completed_at' => 'datetime',
        'metrics' => 'array',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(HrJourneyEvent::class, 'instance_id');
    }
}
