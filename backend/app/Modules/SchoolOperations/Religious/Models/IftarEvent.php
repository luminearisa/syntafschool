<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `iftar_events`.
 */
class IftarEvent extends Model
{
    protected $table = 'iftar_events';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'program_id' => 'integer',
        'ramadan_day_id' => 'integer',
        'mosque_id' => 'integer',
        'campus_id' => 'integer',
        'event_date' => 'date',
        'expected_participants' => 'integer',
        'actual_participants' => 'integer',
        'catering_required' => 'boolean',
        'transportation_required' => 'boolean',
        'meta' => 'array',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(RamadanProgram::class, 'program_id');
    }

    public function ramadanDay(): BelongsTo
    {
        return $this->belongsTo(RamadanDay::class, 'ramadan_day_id');
    }

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }
}
