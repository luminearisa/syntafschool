<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `khatib_assignments`.
 */
class KhatibAssignment extends Model
{
    protected $table = 'khatib_assignments';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'khatib_id' => 'integer',
        'mosque_id' => 'integer',
        'friday_prayer_id' => 'integer',
        'assignment_date' => 'date',
        'assigned_by' => 'integer',
    ];

    public function khatib(): BelongsTo
    {
        return $this->belongsTo(KhatibProfile::class, 'khatib_id');
    }

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function fridayPrayer(): BelongsTo
    {
        return $this->belongsTo(FridayPrayer::class, 'friday_prayer_id');
    }
}
