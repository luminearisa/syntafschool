<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `friday_prayer_assignments`.
 */
class FridayPrayerAssignment extends Model
{
    protected $table = 'friday_prayer_assignments';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'friday_prayer_id' => 'integer',
        'personnel_id' => 'integer',
        'confirmed_at' => 'datetime',
        'substitute_personnel_id' => 'integer',
    ];

    public function fridayPrayer(): BelongsTo
    {
        return $this->belongsTo(FridayPrayer::class, 'friday_prayer_id');
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(ReligiousPersonnelProfile::class, 'personnel_id');
    }

    public function substitutePersonnel(): BelongsTo
    {
        return $this->belongsTo(ReligiousPersonnelProfile::class, 'substitute_personnel_id');
    }
}
