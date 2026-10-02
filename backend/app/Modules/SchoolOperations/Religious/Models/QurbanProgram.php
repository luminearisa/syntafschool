<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `qurban_programs`.
 */
class QurbanProgram extends Model
{
    protected $table = 'qurban_programs';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'eid_event_id' => 'integer',
        'campus_id' => 'integer',
        'animal_types' => 'array',
        'target_beneficiaries' => 'integer',
        'total_animals' => 'integer',
        'total_contribution' => 'decimal:2',
        'meta' => 'array',
    ];

    public function eidEvent(): BelongsTo
    {
        return $this->belongsTo(EidEvent::class, 'eid_event_id');
    }
}
