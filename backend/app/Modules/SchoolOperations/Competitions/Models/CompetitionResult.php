<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionResult — tabel `competition_results`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionResult extends Model
{
    use SoftDeletes;

    protected $table = 'competition_results';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw_score' => 'decimal:2',
            'penalty_total' => 'decimal:2',
            'final_score' => 'decimal:2',
            'aggregate_score' => 'decimal:2',
            'is_winner' => 'boolean',
            'tie_break_applied' => 'boolean',
            'tie_break_detail' => 'array',
            'score_breakdown' => 'array',
            'finalized_at' => 'datetime',
            'published_at' => 'datetime',
            'is_published' => 'boolean',
            'achievement_synced_at' => 'datetime',
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

    public function competitionRound(): BelongsTo
    {
        return $this->belongsTo(CompetitionRound::class);
    }
}
