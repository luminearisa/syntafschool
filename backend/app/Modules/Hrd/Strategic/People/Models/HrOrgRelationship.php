<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\People\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrOrgRelationship extends Model
{
    protected $table = 'hr_org_relationships';

    protected $guarded = [];

    protected $casts = [
        'from_employee_id' => 'integer',
        'to_employee_id' => 'integer',
        'relationship_type' => 'string',
        'source_type' => 'string',
        'source_id' => 'integer',
        'strength' => 'decimal:2',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'notes' => 'string',
        'meta' => 'array',
    ];


}
