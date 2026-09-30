<?php

namespace App\Modules\UKS\Models;

use App\Models\BaseModel;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UksHealthProfile extends BaseModel
{
    protected $table = 'uks_health_profiles';

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
