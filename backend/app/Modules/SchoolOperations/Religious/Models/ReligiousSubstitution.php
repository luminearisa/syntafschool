<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `religious_substitutions`.
 */
class ReligiousSubstitution extends Model
{
    protected $table = 'religious_substitutions';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'assignment_id' => 'integer',
        'original_personnel_id' => 'integer',
        'substitute_personnel_id' => 'integer',
        'requested_by' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ReligiousDutyAssignment::class, 'assignment_id');
    }

    public function originalPersonnel(): BelongsTo
    {
        return $this->belongsTo(ReligiousPersonnelProfile::class, 'original_personnel_id');
    }

    public function substitutePersonnel(): BelongsTo
    {
        return $this->belongsTo(ReligiousPersonnelProfile::class, 'substitute_personnel_id');
    }
}
