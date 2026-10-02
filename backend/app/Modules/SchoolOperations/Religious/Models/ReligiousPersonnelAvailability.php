<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `religious_personnel_availability`.
 */
class ReligiousPersonnelAvailability extends Model
{
    protected $table = 'religious_personnel_availability';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'personnel_id' => 'integer',
        'availability_date' => 'date',
        'recurring' => 'boolean',
    ];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(ReligiousPersonnelProfile::class, 'personnel_id');
    }
}
