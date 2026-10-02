<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Talent\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrOrganization;
use App\Modules\Hrd\Models\HrPosition;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrTemporaryAssignment extends Model
{
    protected $table = 'hr_temporary_assignments';

    protected $guarded = [];

    protected $casts = [
        'employee_id' => 'integer',
        'assignment_type' => 'string',
        'from_position_id' => 'integer',
        'to_position_id' => 'integer',
        'to_organization_id' => 'integer',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'workload_percent' => 'decimal:2',
        'status' => 'string',
        'reason' => 'string',
        'approved_by' => 'integer',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function fromPosition(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'from_position_id');
    }

    public function toPosition(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'to_position_id');
    }

    public function toOrganization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'to_organization_id');
    }
}
