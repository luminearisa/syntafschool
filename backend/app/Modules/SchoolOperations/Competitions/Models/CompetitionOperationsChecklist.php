<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompetitionOperationsChecklist — tabel `competition_operations_checklists`.
 *
 * Modul Competition hanya menyimpan keadaan khas kompetisi. Rujukan ke
 * modul lain (siswa, pegawai, keuangan, fasilitas) sengaja TIDAK dibuatkan
 * relasi Eloquent: modul ini bukan pemilik data tersebut dan mengaksesnya
 * lewat kontrak penyedia (§110).
 */
class CompetitionOperationsChecklist extends Model
{
    use SoftDeletes;

    protected $table = 'competition_operations_checklists';

    /** Kolom `id` dijaga; sisanya diisi lewat layanan yang tervalidasi. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // ── Relasi ke induk di dalam modul ini ──────────────────────────

    public function competitionEdition(): BelongsTo
    {
        return $this->belongsTo(CompetitionEdition::class);
    }
}
