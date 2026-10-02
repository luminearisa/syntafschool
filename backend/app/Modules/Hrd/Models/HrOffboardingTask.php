<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tugas offboarding dan pembebasan tanggung jawab (§40).
 *
 * `clearance_status` dipisah dari `status`: sebuah tugas dapat "selesai" tanpa
 * "dibebaskan" — misalnya laptop dikembalikan tetapi masih menunggu pemeriksaan
 * kondisi. Pemisahan ini yang membuat clearance keuangan/aset tidak bisa
 * dilewati diam-diam.
 */
class HrOffboardingTask extends HrdModel
{
    protected $casts = [
        'requires_clearance' => 'boolean',
        'cleared_at' => 'datetime',
        'due_date' => 'date:Y-m-d',
        'is_mandatory' => 'boolean',
        'blocks_exit' => 'boolean',
        'requires_attachment' => 'boolean',
        'completed_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public const CLEARANCE_PENDING = 'pending';
    public const CLEARANCE_CLEARED = 'cleared';
    public const CLEARANCE_WAIVED = 'waived';
    public const CLEARANCE_REJECTED = 'rejected';

    public function process(): BelongsTo
    {
        return $this->belongsTo(HrOffboardingProcess::class, 'process_id');
    }

    public function templateTask(): BelongsTo
    {
        return $this->belongsTo(HrOffboardingTemplateTask::class, 'template_task_id');
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function ownerEmployee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'owner_employee_id');
    }

    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
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
        return in_array($this->status, ['completed', 'cancelled'], true);
    }

    public function isCleared(): bool
    {
        return in_array($this->clearance_status, [self::CLEARANCE_CLEARED, self::CLEARANCE_WAIVED], true);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function scopePendingClearance(Builder $query): Builder
    {
        return $query->where('requires_clearance', true)->where('clearance_status', self::CLEARANCE_PENDING);
    }
}
