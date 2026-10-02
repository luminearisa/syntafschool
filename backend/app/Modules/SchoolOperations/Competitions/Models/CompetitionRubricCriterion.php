<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionRubricCriterion — tabel `competition_rubric_criteria`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionRubricCriterion extends Model
{
    use SoftDeletes;

    protected $table = 'competition_rubric_criteria';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'min_score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'step' => 'decimal:2',
            'is_required' => 'boolean',
            'required_comment' => 'boolean',
            'penalty_enabled' => 'boolean',
            'max_penalty' => 'decimal:2',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionRubric(): BelongsTo
    {
        return $this->belongsTo(CompetitionRubric::class);
    }

    // ── Relasi ke anak di dalam modul ini ───────────────────────────

    public function competitionScores(): HasMany
    {
        return $this->hasMany(CompetitionScore::class);
    }
}
