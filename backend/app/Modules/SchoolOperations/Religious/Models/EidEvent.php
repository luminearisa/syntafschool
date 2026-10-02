<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `eid_events`.
 */
class EidEvent extends Model
{
    protected $table = 'eid_events';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'campus_id' => 'integer',
        'mosque_id' => 'integer',
        'event_date' => 'date',
        'khatib_id' => 'integer',
        'imam_id' => 'integer',
        'muadzin_id' => 'integer',
        'expected_attendance' => 'integer',
        'actual_attendance' => 'integer',
        'meta' => 'array',
    ];

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function khatib(): BelongsTo
    {
        return $this->belongsTo(KhatibProfile::class, 'khatib_id');
    }

    public function imam(): BelongsTo
    {
        return $this->belongsTo(ImamProfile::class, 'imam_id');
    }

    public function muadzin(): BelongsTo
    {
        return $this->belongsTo(MuadzinProfile::class, 'muadzin_id');
    }

    public function qurbanPrograms(): HasMany
    {
        return $this->hasMany(QurbanProgram::class, 'eid_event_id');
    }
}
