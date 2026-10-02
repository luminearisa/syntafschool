<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `tahajjud_sessions`.
 */
class TahajjudSession extends Model
{
    protected $table = 'tahajjud_sessions';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'program_id' => 'integer',
        'ramadan_day_id' => 'integer',
        'mosque_id' => 'integer',
        'campus_id' => 'integer',
        'dormitory_id' => 'integer',
        'session_date' => 'date',
        'imam_id' => 'integer',
        'is_special' => 'boolean',
        'expected_participants' => 'integer',
        'actual_participants' => 'integer',
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

    public function imam(): BelongsTo
    {
        return $this->belongsTo(ImamProfile::class, 'imam_id');
    }
}
