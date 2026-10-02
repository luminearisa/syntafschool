<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSemanticField extends Model
{
    protected $table = 'hr_semantic_fields';

    protected $guarded = [];

    protected $casts = [
        'entity_code' => 'string',
        'field' => 'string',
        'label' => 'string',
        'data_type' => 'string',
        'is_filterable' => 'boolean',
        'is_dimension' => 'boolean',
        'is_metric' => 'boolean',
        'sensitivity' => 'string',
        'permission' => 'string',
        'source_field' => 'string',
        'meta' => 'array',
    ];


}
