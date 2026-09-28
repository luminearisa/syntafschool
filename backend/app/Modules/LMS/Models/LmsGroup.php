<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Kelompok peserta di dalam course (§19). */
class LmsGroup extends BaseModel
{
    use SoftDeletes;

    protected $table = 'lms_groups';

    protected $casts = ['capacity' => 'integer', 'sort_order' => 'integer'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(LmsGroupMember::class, 'group_id');
    }
}
