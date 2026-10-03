<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionFormat — tabel `competition_formats`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionFormat extends Model
{
    use SoftDeletes;

    protected $table = 'competition_formats';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'supports_advancement' => 'boolean',
            'requires_bracket' => 'boolean',
            'requires_pairing' => 'boolean',
            'settings_schema' => 'array',
            'is_active' => 'boolean',
        ];
    }

    // ── Relasi ke anak di dalam modul ini ───────────────────────────

    public function competitionTemplates(): HasMany
    {
        return $this->hasMany(CompetitionTemplate::class, 'format_id');
    }
}
