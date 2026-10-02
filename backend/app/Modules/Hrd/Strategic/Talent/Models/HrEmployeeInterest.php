<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Talent\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrEmployeeInterest extends Model
{
    protected $table = 'hr_employee_interests';

    protected $guarded = [];

    protected $casts = [
        'employee_id' => 'integer',
        'interest_type' => 'string',
        'key' => 'string',
        'weight' => 'decimal:2',
        'source' => 'string',
        'notes' => 'string',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
