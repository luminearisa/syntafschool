<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionSubmission — tabel `competition_submissions`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionSubmission extends Model
{
    use SoftDeletes;

    protected $table = 'competition_submissions';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'deadline_at' => 'datetime',
            'submitted_at' => 'datetime',
            'is_late' => 'boolean',
            'late_penalty_applied' => 'decimal:2',
            'locked_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'plagiarism_score' => 'decimal:2',
            'plagiarism_checked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionCategory(): BelongsTo
    {
        return $this->belongsTo(CompetitionCategory::class);
    }

    // ── Relasi ke anak di dalam modul ini ───────────────────────────

    public function competitionSubmissionVersions(): HasMany
    {
        return $this->hasMany(CompetitionSubmissionVersion::class);
    }
}
