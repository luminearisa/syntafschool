<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionParticipant — tabel `competition_participants`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionParticipant extends Model
{
    use SoftDeletes;

    protected $table = 'competition_participants';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_captain' => 'boolean',
            'is_substitute' => 'boolean',
            'is_active' => 'boolean',
            'withdrawn_at' => 'datetime',
            'metadata' => 'array',
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

    public function competitionRegistration(): BelongsTo
    {
        return $this->belongsTo(CompetitionRegistration::class);
    }
}
