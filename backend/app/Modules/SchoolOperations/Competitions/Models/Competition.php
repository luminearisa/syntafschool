<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Competition — tabel `competitions`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class Competition extends Model
{
    use SoftDeletes;

    protected $table = 'competitions';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'is_recurring' => 'boolean',
            'approved_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionDomain(): BelongsTo
    {
        return $this->belongsTo(CompetitionDomain::class);
    }

    public function competitionType(): BelongsTo
    {
        return $this->belongsTo(CompetitionType::class);
    }

    // ── Relasi ke anak di dalam modul ini ───────────────────────────

    public function competitionEditions(): HasMany
    {
        return $this->hasMany(CompetitionEdition::class);
    }
}
