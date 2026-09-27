<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keanggotaan kelompok pengasuhan (§8).
 *
 * Anggota yang keluar tidak dihapus: `left_date` diisi, sehingga riwayat
 * "siapa dibina siapa" tetap bisa direkonstruksi untuk laporan dan audit.
 */
class BoardingGroupMember extends BaseModel
{
    use LogsBoardingActivity;

    protected $casts = [
        'joined_date' => 'date',
        'left_date' => 'date',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(BoardingGroup::class, 'group_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** Keanggotaan yang masih berjalan. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('left_date');
    }

    public function scopeForStudent(Builder $query, ?int $studentId): Builder
    {
        return $studentId === null ? $query : $query->where('student_id', $studentId);
    }

    public function isCurrent(): bool
    {
        return $this->left_date === null;
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_GROUP_MEMBER';
    }
}
