<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionRegistrationStatusHistory — tabel `competition_registration_status_histories`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionRegistrationStatusHistory extends Model
{
    use SoftDeletes;

    protected $table = 'competition_registration_status_histories';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionRegistration(): BelongsTo
    {
        return $this->belongsTo(CompetitionRegistration::class);
    }
}
