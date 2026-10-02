<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionJudge — tabel `competition_judges`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionJudge extends Model
{
    use SoftDeletes;

    protected $table = 'competition_judges';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'certification_expires_on' => 'date',
            'is_certified' => 'boolean',
            'conflict_declaration' => 'array',
            'is_active' => 'boolean',
        ];
    }

    // ── Relasi ke anak di dalam modul ini ───────────────────────────

    public function competitionJudgeAssignments(): HasMany
    {
        return $this->hasMany(CompetitionJudgeAssignment::class);
    }

    public function competitionJudgeConflicts(): HasMany
    {
        return $this->hasMany(CompetitionJudgeConflict::class);
    }
}
