<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionScorecard — tabel `competition_scorecards`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionScorecard extends Model
{
    use SoftDeletes;

    protected $table = 'competition_scorecards';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_raw_score' => 'decimal:2',
            'weighted_score' => 'decimal:2',
            'penalty_total' => 'decimal:2',
            'final_score' => 'decimal:2',
            'normalized_score' => 'decimal:2',
            'is_head_judge_override' => 'boolean',
            'submitted_at' => 'datetime',
            'locked_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'offline_created_at' => 'datetime',
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

    public function competitionScoreVersions(): HasMany
    {
        return $this->hasMany(CompetitionScoreVersion::class);
    }

    public function competitionScoreAmendments(): HasMany
    {
        return $this->hasMany(CompetitionScoreAmendment::class);
    }
}
