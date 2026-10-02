<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Proses offboarding (§40).
 *
 * Alurnya sengaja eksplisit: draft → submitted → approved → notice_period →
 * in_progress → cleared → completed. Pegawai baru menjadi `inactive` setelah
 * seluruh tugas bertanda `blocks_exit` selesai — bukan begitu pengunduran diri
 * disetujui.
 */
class HrOffboardingProcess extends HrdModel
{
    protected $casts = [
        'notice_date' => 'date:Y-m-d',
        'last_working_date' => 'date:Y-m-d',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'progress' => 'integer',
        'is_rehireable' => 'boolean',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_NOTICE_PERIOD = 'notice_period';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_CLEARED = 'cleared';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(HrOffboardingTemplate::class, 'template_id');
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
        return $this->hasMany(HrOffboardingTask::class, 'process_id')->orderBy('sort_order');
    }

    /** Tugas penyelesaian yang masih menahan kepindahan ke `inactive`. */
    public function blockingTasks(): HasMany
    {
        return $this->tasks()->where('blocks_exit', true)->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function isFullyCleared(): bool
    {
        return $this->blockingTasks()->count() === 0;
    }
}
