<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Analytics\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrDataCatalog extends Model
{
    protected $table = 'hr_data_catalog';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'dataset' => 'string',
        'owner_role' => 'string',
        'steward_role' => 'string',
        'definition' => 'string',
        'source_tables' => 'array',
        'refresh_frequency' => 'string',
        'sensitivity' => 'string',
        'access_roles' => 'array',
        'retention_policy' => 'string',
        'classification' => 'string',
        'last_reviewed_at' => 'datetime',
        'meta' => 'array',
    ];


}
