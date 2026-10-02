<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionJudgeAssignment — tabel `competition_judge_assignments`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionJudgeAssignment extends Model
{
    use SoftDeletes;

    protected $table = 'competition_judge_assignments';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_head_judge' => 'boolean',
            'assigned_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
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

    public function competitionJudge(): BelongsTo
    {
        return $this->belongsTo(CompetitionJudge::class);
    }

    public function competitionMatch(): BelongsTo
    {
        return $this->belongsTo(CompetitionMatch::class);
    }

    public function competitionRound(): BelongsTo
    {
        return $this->belongsTo(CompetitionRound::class);
    }

    public function competitionSession(): BelongsTo
    {
        return $this->belongsTo(CompetitionSession::class);
    }
}
