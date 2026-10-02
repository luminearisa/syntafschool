<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAiCostLog extends Model
{
    protected $table = 'hr_ai_cost_logs';

    protected $guarded = [];

    protected $casts = [
        'period' => 'string',
        'provider_code' => 'string',
        'model_code' => 'string',
        'feature' => 'string',
        'user_id' => 'integer',
        'requests' => 'integer',
        'tokens' => 'integer',
        'cost' => 'decimal:2',
        'currency' => 'string',
        'meta' => 'array',
    ];


}
