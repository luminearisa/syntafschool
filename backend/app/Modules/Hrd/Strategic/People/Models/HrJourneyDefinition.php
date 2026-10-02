<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\People\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrJourneyDefinition extends Model
{
    protected $table = 'hr_journey_definitions';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'journey_type' => 'string',
        'trigger_event' => 'string',
        'description' => 'string',
        'target_audience' => 'array',
        'status' => 'string',
        'version' => 'integer',
        'owner_role' => 'string',
        'meta' => 'array',
    ];


}
