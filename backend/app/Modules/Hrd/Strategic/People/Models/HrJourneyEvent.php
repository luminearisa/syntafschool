<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\People\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Strategic\People\Models\HrJourneyInstance;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrJourneyEvent extends Model
{
    protected $table = 'hr_journey_events';

    protected $guarded = [];

    protected $casts = [
        'instance_id' => 'integer',
        'stage_code' => 'string',
        'event_type' => 'string',
        'employee_id' => 'integer',
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'handled_by' => 'string',
        'meta' => 'array',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(HrJourneyInstance::class, 'instance_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
