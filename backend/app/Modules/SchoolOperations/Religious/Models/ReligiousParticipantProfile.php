<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model tabel `religious_participant_profiles`.
 */
class ReligiousParticipantProfile extends Model
{
    protected $table = 'religious_participant_profiles';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'student_id' => 'integer',
        'employee_id' => 'integer',
        'interest_areas' => 'array',
        'meta' => 'array',
    ];
}
