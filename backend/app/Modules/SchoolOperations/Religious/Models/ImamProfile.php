<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `imam_profiles`.
 */
class ImamProfile extends Model
{
    protected $table = 'imam_profiles';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'personnel_id' => 'integer',
        'campus_id' => 'integer',
        'mosque_id' => 'integer',
        'availability' => 'array',
        'rating' => 'decimal:2',
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
        return $this->hasMany(CongregationalPrayer::class, 'imam_id');
    }

    public function imamAssignments(): HasMany
    {
        return $this->hasMany(ImamAssignment::class, 'imam_id');
    }

    public function fridayPrayers(): HasMany
    {
        return $this->hasMany(FridayPrayer::class, 'imam_id');
    }

    public function ramadanActivities(): HasMany
    {
        return $this->hasMany(RamadanActivity::class, 'imam_id');
    }

    public function tarawihSessions(): HasMany
    {
        return $this->hasMany(TarawihSession::class, 'imam_id');
    }

    public function tahajjudSessions(): HasMany
    {
        return $this->hasMany(TahajjudSession::class, 'imam_id');
    }

    public function eidEvents(): HasMany
    {
        return $this->hasMany(EidEvent::class, 'imam_id');
    }
}
