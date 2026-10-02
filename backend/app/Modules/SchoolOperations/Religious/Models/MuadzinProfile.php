<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `muadzin_profiles`.
 */
class MuadzinProfile extends Model
{
    protected $table = 'muadzin_profiles';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'personnel_id' => 'integer',
        'campus_id' => 'integer',
        'mosque_id' => 'integer',
        'availability' => 'array',
        'meta' => 'array',
    ];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(ReligiousPersonnelProfile::class, 'personnel_id');
    }

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function congregationalPrayers(): HasMany
    {
        return $this->hasMany(CongregationalPrayer::class, 'muadzin_id');
    }

    public function muadzinAssignments(): HasMany
    {
        return $this->hasMany(MuadzinAssignment::class, 'muadzin_id');
    }

    public function fridayPrayers(): HasMany
    {
        return $this->hasMany(FridayPrayer::class, 'muadzin_id');
    }

    public function ramadanActivities(): HasMany
    {
        return $this->hasMany(RamadanActivity::class, 'muadzin_id');
    }

    public function tarawihSessions(): HasMany
    {
        return $this->hasMany(TarawihSession::class, 'muadzin_id');
    }

    public function eidEvents(): HasMany
    {
        return $this->hasMany(EidEvent::class, 'muadzin_id');
    }
}
