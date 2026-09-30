<?php

namespace App\Modules\UKS\Models;

use App\Models\BaseModel;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UksImmunization extends BaseModel
{
    protected $table = 'uks_immunizations';

    protected $casts = [
        'given_at' => 'date',
        'next_due_at' => 'date',
        'dose_number' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
