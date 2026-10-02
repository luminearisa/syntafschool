<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionRegistrationField — tabel `competition_registration_fields`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionRegistrationField extends Model
{
    use SoftDeletes;

    protected $table = 'competition_registration_fields';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_system' => 'boolean',
            'options' => 'array',
            'validation_rules' => 'array',
            'is_active' => 'boolean',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionRegistrationForm(): BelongsTo
    {
        return $this->belongsTo(CompetitionRegistrationForm::class);
    }

    // ── Relasi ke anak di dalam modul ini ───────────────────────────

    public function competitionRegistrationValues(): HasMany
    {
        return $this->hasMany(CompetitionRegistrationValue::class);
    }
}
