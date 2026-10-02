<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `ramadan_days`.
 */
class RamadanDay extends Model
{
    protected $table = 'ramadan_days';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'program_id' => 'integer',
        'hijri_day' => 'integer',
        'gregorian_date' => 'date',
        'is_holiday' => 'boolean',
        'meta' => 'array',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(RamadanProgram::class, 'program_id');
    }

    public function ramadanActivities(): HasMany
    {
        return $this->hasMany(RamadanActivity::class, 'ramadan_day_id');
    }

    public function tarawihSessions(): HasMany
    {
        return $this->hasMany(TarawihSession::class, 'ramadan_day_id');
    }

    public function tahajjudSessions(): HasMany
    {
        return $this->hasMany(TahajjudSession::class, 'ramadan_day_id');
    }

    public function iftarEvents(): HasMany
    {
        return $this->hasMany(IftarEvent::class, 'ramadan_day_id');
    }

    public function suhoorEvents(): HasMany
    {
        return $this->hasMany(SuhoorEvent::class, 'ramadan_day_id');
    }
}
