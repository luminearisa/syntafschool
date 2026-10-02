<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `qurban_distributions`.
 */
class QurbanDistribution extends Model
{
    protected $table = 'qurban_distributions';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'program_id' => 'integer',
        'animal_id' => 'integer',
        'recipient_id' => 'integer',
        'portion_count' => 'integer',
        'distributed_at' => 'datetime',
        'distributed_by' => 'integer',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(RamadanProgram::class, 'program_id');
    }

    public function animal(): BelongsTo
    {
        return $this->belongsTo(QurbanAnimal::class, 'animal_id');
    }
}
