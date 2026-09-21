<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak perubahan satu skor.
 *
 * Setiap perubahan nilai mencatat nilai lama, nilai baru, alasan, dan siapa
 * yang mengubah — tidak boleh ada perubahan nilai yang tidak terlacak.
 */
class ScoreRevision extends BaseModel
{
    protected $casts = [
        'old_score' => 'decimal:2',
        'new_score' => 'decimal:2',
    ];

    public function score(): BelongsTo
    {
        return $this->belongsTo(Score::class);
    }

    /**
     * Pengguna yang mengubah (bukan pegawai — audit melekat pada akun login).
     */
    public function reviser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by');
    }
}
