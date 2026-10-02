<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAiProvider extends Model
{
    protected $table = 'hr_ai_providers';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'provider_type' => 'string',
        'base_url' => 'string',
        'credential_ref' => 'string',
        'data_region' => 'string',
        'is_private' => 'boolean',
        'approved_for_sensitive' => 'boolean',
        'status' => 'string',
        'priority' => 'integer',
        'config' => 'array',
        'meta' => 'array',
    ];


}
