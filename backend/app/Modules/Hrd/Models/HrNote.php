<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catatan HR (§75 hr_notes).
 *
 * `visibility` menentukan siapa yang boleh membacanya: catatan kedisiplinan tidak
 * boleh muncul di linimasa yang dilihat pegawai, sedangkan catatan `employee`
 * memang untuk dibaca pemiliknya.
 */
class HrNote extends HrdModel
{
    use SoftDeletes;

    protected $casts = ['is_pinned' => 'boolean'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeVisibleTo(Builder $query, string $level): Builder
    {
        $allowed = match ($level) {
            'employee' => ['employee'],
            'manager' => ['manager', 'employee'],
            'hr' => ['hr', 'manager', 'employee'],
            default => ['private', 'hr', 'manager', 'employee'],
        };

        return $query->whereIn('visibility', $allowed);
    }

    public function scopePinnedFirst(Builder $query): Builder
    {
        return $query->orderByDesc('is_pinned')->orderByDesc('created_at');
    }
}
