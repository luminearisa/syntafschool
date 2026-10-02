<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `religious_teacher_profiles`.
 */
class ReligiousTeacherProfile extends Model
{
    protected $table = 'religious_teacher_profiles';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'personnel_id' => 'integer',
        'employee_id' => 'integer',
        'teacher_id' => 'integer',
        'subjects' => 'array',
        'teaching_levels' => 'array',
        'meta' => 'array',
    ];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(ReligiousPersonnelProfile::class, 'personnel_id');
    }
}
