<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Profil konselor BK — penugasan pengguna, kekhususan, kapasitas kasus.

Beban kerja dihitung dari kasus aktif yang ditugaskan, bukan dari kolom di sini;
`max_active_cases` dipakai `BkAssignmentService` untuk memperingatkan kelebihan
beban sejak dini.
 *
 * Tabel: `bk_counselors`
 */
class BkCounselor extends BkModel
{
    protected $table = 'bk_counselors';

    use SoftDeletes;

    protected $casts = [
        'deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function availability(): HasMany
    {
        return $this->hasMany(BkCounselorAvailability::class, 'counselor_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(BkCaseAssignment::class, 'user_id');
    }

    /** Konselor yang masih aktif menerima penugasan. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE');
    }

    /** Beban kasus aktif saat ini (satu kueri, tanpa memuat model kasus). */
    public function activeCaseCount(): int
    {
        return BkCase::query()
            ->where('assigned_counselor_id', $this->user_id)
            ->whereNotIn('status', ['CLOSED', 'RESOLVED'])
            ->count();
    }
}
