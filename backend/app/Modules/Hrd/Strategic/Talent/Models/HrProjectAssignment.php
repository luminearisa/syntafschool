<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Talent\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrProjectAssignment extends Model
{
    protected $table = 'hr_project_assignments';

    protected $guarded = [];

    protected $casts = [
        'employee_id' => 'integer',
        'opportunity_id' => 'integer',
        'project_code' => 'string',
        'project_name' => 'string',
        'role_title' => 'string',
        'allocation_percent' => 'decimal:2',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'primary_job_overlap' => 'boolean',
        'status' => 'string',
        'manager_employee_id' => 'integer',
        'notes' => 'string',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'manager_employee_id');
    }
}
