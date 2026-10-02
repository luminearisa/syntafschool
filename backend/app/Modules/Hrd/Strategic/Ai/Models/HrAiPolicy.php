<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAiPolicy extends Model
{
    protected $table = 'hr_ai_policies';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'purpose' => 'string',
        'rules' => 'array',
        'applies_to' => 'array',
        'data_classification_limit' => 'string',
        'requires_human_review' => 'boolean',
        'prohibited_actions' => 'array',
        'status' => 'string',
        'effective_from' => 'date:Y-m-d',
        'owner_role' => 'string',
        'meta' => 'array',
    ];


}
