<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrDataQualityRule extends Model
{
    protected $table = 'hr_data_quality_rules';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'dataset' => 'string',
        'rule_type' => 'string',
        'expression' => 'string',
        'severity' => 'string',
        'owner_role' => 'string',
        'status' => 'string',
        'last_run_at' => 'datetime',
        'last_result' => 'array',
        'meta' => 'array',
    ];


}
