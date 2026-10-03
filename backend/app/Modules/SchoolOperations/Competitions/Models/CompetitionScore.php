<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionScore — tabel `competition_scores`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionScore extends Model
{
    use SoftDeletes;

    protected $table = 'competition_scores';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw_score' => 'decimal:2',
            'weighted_score' => 'decimal:2',
            'is_penalty' => 'boolean',
            'penalty_value' => 'decimal:2',
            'entered_at' => 'datetime',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionRubricCriterion(): BelongsTo
    {
        return $this->belongsTo(CompetitionRubricCriterion::class, 'criterion_id');
    }

    public function competitionScorecard(): BelongsTo
    {
        return $this->belongsTo(CompetitionScorecard::class, 'scorecard_id');
    }
}
