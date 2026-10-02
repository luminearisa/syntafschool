<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Workforce\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrDepartment;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Models\HrPosition;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrWorkforceGap extends Model
{
    protected $table = 'hr_workforce_gaps';

    protected $guarded = [];

    protected $casts = [
        'plan_id' => 'integer',
        'version' => 'integer',
        'organization_id' => 'integer',
        'department_id' => 'integer',
        'position_id' => 'integer',
        'gap_type' => 'string',
        'dimension_key' => 'string',
        'required_value' => 'decimal:2',
        'available_value' => 'decimal:2',
        'gap_value' => 'decimal:2',
        'severity' => 'string',
        'recommended_actions' => 'array',
        'evidence' => 'array',
        'status' => 'string',
        'meta' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }
}
