<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `khatib_profiles`.
 */
class KhatibProfile extends Model
{
    protected $table = 'khatib_profiles';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'personnel_id' => 'integer',
        'languages' => 'array',
        'campus_id' => 'integer',
        'meta' => 'array',
    ];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(ReligiousPersonnelProfile::class, 'personnel_id');
    }

    public function khatibAssignments(): HasMany
    {
        return $this->hasMany(KhatibAssignment::class, 'khatib_id');
    }

    public function fridayPrayers(): HasMany
    {
        return $this->hasMany(FridayPrayer::class, 'khatib_id');
    }

    public function khutbahs(): HasMany
    {
        return $this->hasMany(Khutbah::class, 'khatib_id');
    }

    public function eidEvents(): HasMany
    {
        return $this->hasMany(EidEvent::class, 'khatib_id');
    }
}
