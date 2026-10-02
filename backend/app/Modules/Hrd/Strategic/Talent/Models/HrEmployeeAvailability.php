<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Talent\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrEmployeeAvailability extends Model
{
    protected $table = 'hr_employee_availability';

    protected $guarded = [];

    protected $casts = [
        'employee_id' => 'integer',
        'as_of' => 'date:Y-m-d',
        'capacity_percent' => 'decimal:2',
        'allocated_percent' => 'decimal:2',
        'available_percent' => 'decimal:2',
        'available_from' => 'date:Y-m-d',
        'notes' => 'string',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
