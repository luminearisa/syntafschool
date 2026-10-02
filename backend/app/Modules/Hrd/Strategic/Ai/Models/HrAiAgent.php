<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAiAgent extends Model
{
    protected $table = 'hr_ai_agents';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'agent_group' => 'string',
        'description' => 'string',
        'system_prompt' => 'string',
        'allowed_tools' => 'array',
        'required_permissions' => 'array',
        'model_code' => 'string',
        'max_tokens' => 'integer',
        'requires_human_review' => 'boolean',
        'status' => 'string',
        'version' => 'integer',
        'meta' => 'array',
    ];


}
