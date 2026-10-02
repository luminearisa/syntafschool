<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionRubric — tabel `competition_rubrics`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionRubric extends Model
{
    use SoftDeletes;

    protected $table = 'competition_rubrics';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_weight' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'published_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionCategory(): BelongsTo
    {
        return $this->belongsTo(CompetitionCategory::class);
    }

    public function competitionEdition(): BelongsTo
    {
        return $this->belongsTo(CompetitionEdition::class);
    }

    // ── Relasi ke anak di dalam modul ini ───────────────────────────

    public function competitionRubricCriterions(): HasMany
    {
        return $this->hasMany(CompetitionRubricCriterion::class);
    }

    public function competitionScorecards(): HasMany
    {
        return $this->hasMany(CompetitionScorecard::class);
    }
}
