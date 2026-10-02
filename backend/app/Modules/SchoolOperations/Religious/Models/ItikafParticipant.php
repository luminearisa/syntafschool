<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `itikaf_participants`.
 */
class ItikafParticipant extends Model
{
    protected $table = 'itikaf_participants';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'program_id' => 'integer',
        'student_id' => 'integer',
        'employee_id' => 'integer',
        'consent_document_id' => 'integer',
        'accommodation_area_id' => 'integer',
        'meal_required' => 'boolean',
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(RamadanProgram::class, 'program_id');
    }
}
