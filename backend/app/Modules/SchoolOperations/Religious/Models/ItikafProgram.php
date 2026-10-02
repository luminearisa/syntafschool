<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `itikaf_programs`.
 */
class ItikafProgram extends Model
{
    protected $table = 'itikaf_programs';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'program_id' => 'integer',
        'mosque_id' => 'integer',
        'campus_id' => 'integer',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'capacity' => 'integer',
        'registration_required' => 'boolean',
        'consent_required' => 'boolean',
        'meals_provided' => 'boolean',
        'accommodation_required' => 'boolean',
        'security_required' => 'boolean',
        'clinic_required' => 'boolean',
        'expected_participants' => 'integer',
        'meta' => 'array',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(RamadanProgram::class, 'program_id');
    }

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }
}
