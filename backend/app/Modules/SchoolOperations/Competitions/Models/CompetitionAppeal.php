<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionAppeal — tabel `competition_appeals`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionAppeal extends Model
{
    use SoftDeletes;

    protected $table = 'competition_appeals';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'submitted_at' => 'datetime',
            'deadline_at' => 'datetime',
            'is_late' => 'boolean',
            'decided_at' => 'datetime',
            'is_final' => 'boolean',
            'review_started_at' => 'datetime',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionEdition(): BelongsTo
    {
        return $this->belongsTo(CompetitionEdition::class);
    }

    public function competitionProtest(): BelongsTo
    {
        return $this->belongsTo(CompetitionProtest::class);
    }
}
