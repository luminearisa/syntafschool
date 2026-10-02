<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionJudgeConflict — tabel `competition_judge_conflicts`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionJudgeConflict extends Model
{
    use SoftDeletes;

    protected $table = 'competition_judge_conflicts';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'declared_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionJudge(): BelongsTo
    {
        return $this->belongsTo(CompetitionJudge::class);
    }
}
