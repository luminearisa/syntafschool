<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAiToolPermission extends Model
{
    protected $table = 'hr_ai_tool_permissions';

    protected $guarded = [];

    protected $casts = [
        'tool_code' => 'string',
        'principal_type' => 'string',
        'principal_reference' => 'string',
        'scope' => 'string',
        'allowed' => 'boolean',
        'field_masking' => 'array',
        'meta' => 'array',
    ];


}
