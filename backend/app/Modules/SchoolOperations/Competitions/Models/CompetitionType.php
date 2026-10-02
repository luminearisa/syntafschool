<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionType — tabel `competition_types`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionType extends Model
{
    use SoftDeletes;

    protected $table = 'competition_types';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requires_team' => 'boolean',
            'requires_judge' => 'boolean',
            'requires_submission' => 'boolean',
            'allows_multiple_rounds' => 'boolean',
            'supports_bracket' => 'boolean',
            'supports_heat' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionDomain(): BelongsTo
    {
        return $this->belongsTo(CompetitionDomain::class);
    }

    // ── Relasi ke anak di dalam modul ini ───────────────────────────

    public function competitions(): HasMany
    {
        return $this->hasMany(Competition::class);
    }

    public function competitionTemplates(): HasMany
    {
        return $this->hasMany(CompetitionTemplate::class);
    }
}
