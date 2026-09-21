<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mutabaah harian seorang peserta.
 *
 * Entri diambil dari `TahfizMutabaahItem` (item konfigurabel — tidak ditulis
 * keras di kode). Satu baris per peserta per tanggal.
 */
class TahfizMutabaah extends BaseModel
{
    // Migrasi menamai tabel ini "tahfiz_mutabaah" (tunggal).
    protected $table = 'tahfiz_mutabaah';

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    public function scopeBetweenDates(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TahfizParticipant::class, 'participant_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TahfizMutabaahEntry::class, 'mutabaah_id');
    }

    /** Jumlah item yang selesai dari total entri. */
    public function completedCount(): int
    {
        return $this->entries()->where('done', true)->count();
    }
}
