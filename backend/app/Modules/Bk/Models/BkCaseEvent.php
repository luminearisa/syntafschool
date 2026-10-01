<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\CaseEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Timeline kasus — append-only.

`summary` sengaja hanya memuat ringkasan aman untuk daftar; isi catatan konseling
tidak pernah masuk ke sini.
 *
 * Tabel: `bk_case_events`
 */
class BkCaseEvent extends BkModel
{
    protected $table = 'bk_case_events';

    protected $casts = [
        'occurred_at' => 'datetime',
        'payload' => 'array',
        'event_type' => \App\Modules\Bk\Domain\Enums\CaseEventType::class,
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(BkReferral::class, 'referral_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeForCase(Builder $query, int $caseId): Builder
    {
        return $query->where('case_id', $caseId);
    }
}
