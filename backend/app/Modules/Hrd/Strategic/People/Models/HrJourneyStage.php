<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\People\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrJourneyStage extends Model
{
    protected $table = 'hr_journey_stages';

    protected $guarded = [];

    protected $casts = [
        'journey_code' => 'string',
        'sequence' => 'integer',
        'code' => 'string',
        'name' => 'string',
        'stage_type' => 'string',
        'offset_days' => 'integer',
        'owner_role' => 'string',
        'config' => 'array',
        'is_required' => 'boolean',
        'meta' => 'array',
    ];


}
