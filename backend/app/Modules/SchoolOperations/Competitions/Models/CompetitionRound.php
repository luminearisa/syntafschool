<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionRound — tabel `competition_rounds`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionRound extends Model
{
    use SoftDeletes;

    protected $table = 'competition_rounds';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qualification_threshold' => 'decimal:2',
            'starts_on' => 'datetime',
            'ends_on' => 'datetime',
            'is_final' => 'boolean',
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

    public function competitionGroups(): HasMany
    {
        return $this->hasMany(CompetitionGroup::class);
    }

    public function competitionBrackets(): HasMany
    {
        return $this->hasMany(CompetitionBracket::class);
    }

    public function competitionAdvancements(): HasMany
    {
        return $this->hasMany(CompetitionAdvancement::class);
    }

    public function competitionSessions(): HasMany
    {
        return $this->hasMany(CompetitionSession::class);
    }

    public function competitionMatchs(): HasMany
    {
        return $this->hasMany(CompetitionMatch::class);
    }

    public function competitionJudgeAssignments(): HasMany
    {
        return $this->hasMany(CompetitionJudgeAssignment::class);
    }

    public function competitionTieBreaks(): HasMany
    {
        return $this->hasMany(CompetitionTieBreak::class);
    }

    public function competitionResults(): HasMany
    {
        return $this->hasMany(CompetitionResult::class);
    }

    public function competitionRankings(): HasMany
    {
        return $this->hasMany(CompetitionRanking::class);
    }
}
