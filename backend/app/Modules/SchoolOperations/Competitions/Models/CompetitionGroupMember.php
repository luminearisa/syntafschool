<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionGroupMember — tabel `competition_group_members`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionGroupMember extends Model
{
    use SoftDeletes;

    protected $table = 'competition_group_members';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'points' => 'decimal:2',
            'score_for' => 'decimal:2',
            'score_against' => 'decimal:2',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionGroup(): BelongsTo
    {
        return $this->belongsTo(CompetitionGroup::class);
    }
}
