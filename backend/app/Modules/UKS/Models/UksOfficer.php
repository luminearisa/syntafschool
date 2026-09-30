<?php

namespace App\Modules\UKS\Models;

use App\Models\BaseModel;
use App\Models\Employee;
use App\Modules\UKS\Domain\Enums\OfficerRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UksOfficer extends BaseModel
{
    protected $table = 'uks_officers';

    protected $casts = [
        'is_active' => 'boolean',
        'appointed_at' => 'date',
        'role' => OfficerRole::class,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
