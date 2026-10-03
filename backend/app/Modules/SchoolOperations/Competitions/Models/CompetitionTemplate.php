<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionTemplate — tabel `competition_templates`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'competition_templates';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requires_eligibility' => 'boolean',
            'requires_parent_consent' => 'boolean',
            'requires_selection' => 'boolean',
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionDomain(): BelongsTo
    {
        return $this->belongsTo(CompetitionDomain::class, 'competition_domain_id');
    }

    public function competitionFormat(): BelongsTo
    {
        return $this->belongsTo(CompetitionFormat::class, 'format_id');
    }

    public function competitionType(): BelongsTo
    {
        return $this->belongsTo(CompetitionType::class);
    }

    // ── Relasi ke anak di dalam modul ini ───────────────────────────

    public function competitionTemplateCategorys(): HasMany
    {
        return $this->hasMany(CompetitionTemplateCategory::class, 'template_id');
    }

    public function competitionTemplateRules(): HasMany
    {
        return $this->hasMany(CompetitionTemplateRule::class, 'template_id');
    }

    public function competitionTemplateRounds(): HasMany
    {
        return $this->hasMany(CompetitionTemplateRound::class, 'template_id');
    }
}
