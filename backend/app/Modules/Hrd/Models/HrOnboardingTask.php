<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tugas onboarding nyata untuk seorang pegawai (§38, §39).
 *
 * Setiap tugas punya pemilik, tenggat, status, dan lampiran. Penanggung jawab
 * dapat berupa pegawai baru (`employee`), atasan (`manager`), HR, TI, atau
 * keuangan — dengan `owner_role` untuk kasus di mana pemiliknya sebuah peran,
 * bukan orang tertentu.
 */
class HrOnboardingTask extends HrdModel
{
    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'is_mandatory' => 'boolean',
        'requires_attachment' => 'boolean',
        'blocks_activation' => 'boolean',
        'completed_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_WAITING_REVIEW = 'waiting_review';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_WAIVED = 'waived';
    public const STATUS_OVERDUE = 'overdue';

    public function process(): BelongsTo
    {
        return $this->belongsTo(HrOnboardingProcess::class, 'process_id');
    }

    public function templateTask(): BelongsTo
    {
        return $this->belongsTo(HrOnboardingTemplateTask::class, 'template_task_id');
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function ownerEmployee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'owner_employee_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeDocument::class, 'document_id');
    }

    public function isDone(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_WAIVED], true);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_WAIVED]);
    }

    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('due_date')
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }
}
