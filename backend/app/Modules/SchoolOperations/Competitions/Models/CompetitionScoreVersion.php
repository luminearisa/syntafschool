<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionScoreVersion — tabel `competition_score_versions`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionScoreVersion extends Model
{
    use SoftDeletes;

    protected $table = 'competition_score_versions';

    public $timestamps = false;

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'previous_total' => 'decimal:2',
            'new_total' => 'decimal:2',
            'previous_payload' => 'array',
            'new_payload' => 'array',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionScorecard(): BelongsTo
    {
        return $this->belongsTo(CompetitionScorecard::class, 'scorecard_id');
    }
}
