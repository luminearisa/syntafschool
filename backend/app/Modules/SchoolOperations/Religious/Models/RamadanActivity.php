<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `ramadan_activities`.
 */
class RamadanActivity extends Model
{
    protected $table = 'ramadan_activities';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'program_id' => 'integer',
        'ramadan_day_id' => 'integer',
        'mosque_id' => 'integer',
        'area_id' => 'integer',
        'imam_id' => 'integer',
        'muadzin_id' => 'integer',
        'rakaat_count' => 'integer',
        'rakaat_config' => 'array',
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

    public function area(): BelongsTo
    {
        return $this->belongsTo(MosqueArea::class, 'area_id');
    }

    public function imam(): BelongsTo
    {
        return $this->belongsTo(ImamProfile::class, 'imam_id');
    }

    public function muadzin(): BelongsTo
    {
        return $this->belongsTo(MuadzinProfile::class, 'muadzin_id');
    }
}
