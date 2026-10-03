<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionEligibilityEvaluation — tabel `competition_eligibility_evaluations`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionEligibilityEvaluation extends Model
{
    use SoftDeletes;

    protected $table = 'competition_eligibility_evaluations';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'reasons' => 'array',
            'evidence' => 'array',
            'evaluated_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionRegistration(): BelongsTo
    {
        return $this->belongsTo(CompetitionRegistration::class, 'registration_id');
    }

    // ── Relasi ke anak di dalam modul ini ───────────────────────────

    public function competitionEligibilityRuleResults(): HasMany
    {
        return $this->hasMany(CompetitionEligibilityRuleResult::class, 'evaluation_id');
    }
}
