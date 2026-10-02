<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `religious_duty_rosters`.
 */
class ReligiousDutyRoster extends Model
{
    protected $table = 'religious_duty_rosters';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'mosque_id' => 'integer',
        'campus_id' => 'integer',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'published_at' => 'datetime',
        'published_by' => 'integer',
        'approved_by' => 'integer',
        'meta' => 'array',
    ];

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function congregationalPrayers(): HasMany
    {
        return $this->hasMany(CongregationalPrayer::class, 'roster_id');
    }

    public function imamAssignments(): HasMany
    {
        return $this->hasMany(ImamAssignment::class, 'roster_id');
    }

    public function muadzinAssignments(): HasMany
    {
        return $this->hasMany(MuadzinAssignment::class, 'roster_id');
    }

    public function religiousDutyAssignments(): HasMany
    {
        return $this->hasMany(ReligiousDutyAssignment::class, 'roster_id');
    }
}
