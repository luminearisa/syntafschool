<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\AssignmentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Riwayat penugasan konselor pada kasus.

Penugasan lama tidak dihapus melainkan ditutup (`ended_at`, `is_active`), sehingga
pertanyaan "siapa menangani kasus ini pada bulan lalu?" tetap dapat dijawab.
 *
 * Tabel: `bk_case_assignments`
 */
class BkCaseAssignment extends BkModel
{
    protected $table = 'bk_case_assignments';

    protected $casts = [
        'assigned_at' => 'datetime',
        'ended_at' => 'datetime',
        'is_active' => 'boolean',
        'assignment_type' => \App\Modules\Bk\Domain\Enums\AssignmentType::class,
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
