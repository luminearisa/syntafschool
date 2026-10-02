<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionAdvancement — tabel `competition_advancements`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionAdvancement extends Model
{
    use SoftDeletes;

    protected $table = 'competition_advancements';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'score_at_advancement' => 'decimal:2',
            'decided_at' => 'datetime',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionRound(): BelongsTo
    {
        return $this->belongsTo(CompetitionRound::class);
    }
}
