<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionEligibilityRuleResult — tabel `competition_eligibility_rule_results`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionEligibilityRuleResult extends Model
{
    use SoftDeletes;

    protected $table = 'competition_eligibility_rule_results';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
            'weight' => 'decimal:2',
            'contributed_score' => 'decimal:2',
            'evidence' => 'array',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionEligibilityEvaluation(): BelongsTo
    {
        return $this->belongsTo(CompetitionEligibilityEvaluation::class);
    }
}
