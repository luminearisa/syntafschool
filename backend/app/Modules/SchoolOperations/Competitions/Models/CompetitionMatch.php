<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionMatch — tabel `competition_matches`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionMatch extends Model
{
    use SoftDeletes;

    protected $table = 'competition_matches';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'score_a' => 'decimal:2',
            'score_b' => 'decimal:2',
            'is_draw' => 'boolean',
            'officials' => 'array',
            'metadata' => 'array',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionCategory(): BelongsTo
    {
        return $this->belongsTo(CompetitionCategory::class);
    }

    public function competitionGroup(): BelongsTo
    {
        return $this->belongsTo(CompetitionGroup::class);
    }

    public function competitionRound(): BelongsTo
    {
        return $this->belongsTo(CompetitionRound::class);
    }

    public function competitionSession(): BelongsTo
    {
        return $this->belongsTo(CompetitionSession::class);
    }

    // ── Relasi ke anak di dalam modul ini ───────────────────────────

    public function competitionJudgeAssignments(): HasMany
    {
        return $this->hasMany(CompetitionJudgeAssignment::class);
    }
}
