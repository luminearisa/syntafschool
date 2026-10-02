<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionGroup — tabel `competition_groups`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionGroup extends Model
{
    use SoftDeletes;

    protected $table = 'competition_groups';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionRound(): BelongsTo
    {
        return $this->belongsTo(CompetitionRound::class);
    }

    // ── Relasi ke anak di dalam modul ini ───────────────────────────

    public function competitionGroupMembers(): HasMany
    {
        return $this->hasMany(CompetitionGroupMember::class);
    }

    public function competitionMatchs(): HasMany
    {
        return $this->hasMany(CompetitionMatch::class);
    }
}
