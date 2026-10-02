<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAiTool extends Model
{
    protected $table = 'hr_ai_tools';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'tool_group' => 'string',
        'description' => 'string',
        'input_schema' => 'array',
        'output_schema' => 'array',
        'handler' => 'string',
        'required_permission' => 'string',
        'sensitivity' => 'string',
        'is_write' => 'boolean',
        'requires_confirmation' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];


}
