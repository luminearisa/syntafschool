<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `qurban_animals`.
 */
class QurbanAnimal extends Model
{
    protected $table = 'qurban_animals';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'program_id' => 'integer',
        'weight_kg' => 'decimal:2',
        'contribution_amount' => 'decimal:2',
        'sacrificed_at' => 'datetime',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(RamadanProgram::class, 'program_id');
    }

    public function qurbanDistributions(): HasMany
    {
        return $this->hasMany(QurbanDistribution::class, 'animal_id');
    }
}
