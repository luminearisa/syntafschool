<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Proses onboarding untuk satu pegawai (§38, §39).
 *
 * `progress` hanya menghitung tugas **wajib**; tugas opsional tidak pernah
 * menahan penyelesaian. Perpindahan pegawai ke `active` dapat ditahan oleh tugas
 * bertanda `blocks_activation` — itulah yang membuat onboarding bukan sekadar
 * daftar centang.
 */
class HrOnboardingProcess extends HrdModel
{
    protected $casts = [
        'is_preboarding' => 'boolean',
        'start_date' => 'date:Y-m-d',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'progress' => 'integer',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PREBOARDING = 'preboarding';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(HrOnboardingTemplate::class, 'template_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(HrTransaction::class, 'transaction_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(HrOnboardingTask::class, 'process_id')->orderBy('sort_order');
    }

    public function mandatoryTasks(): HasMany
    {
        return $this->tasks()->where('is_mandatory', true);
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
