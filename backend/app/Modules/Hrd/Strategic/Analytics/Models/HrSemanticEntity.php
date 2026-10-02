<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSemanticEntity extends Model
{
    protected $table = 'hr_semantic_entities';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'source_model' => 'string',
        'description' => 'string',
        'allowed_filters' => 'array',
        'allowed_dimensions' => 'array',
        'allowed_metrics' => 'array',
        'sensitivity' => 'string',
        'requires_permission' => 'string',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];


}
