<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `muadzin_assignments`.
 */
class MuadzinAssignment extends Model
{
    protected $table = 'muadzin_assignments';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'muadzin_id' => 'integer',
        'mosque_id' => 'integer',
        'roster_id' => 'integer',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'assigned_by' => 'integer',
    ];

    public function muadzin(): BelongsTo
    {
        return $this->belongsTo(MuadzinProfile::class, 'muadzin_id');
    }

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function roster(): BelongsTo
    {
        return $this->belongsTo(ReligiousDutyRoster::class, 'roster_id');
    }
}
