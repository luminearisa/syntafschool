<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsGroupMember extends BaseModel
{
    protected $table = 'lms_group_members';

    public function group(): BelongsTo
    {
        return $this->belongsTo(LmsGroup::class, 'group_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }
}
