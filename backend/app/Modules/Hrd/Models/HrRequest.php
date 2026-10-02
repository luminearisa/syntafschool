<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Permintaan HR (§45, §46).
 *
 * Nilai disimpan dalam tiga tahap — `current_values` → `requested_values` →
 * `applied_values` — sehingga selalu jelas apa yang diminta, apa yang disetujui,
 * dan apa yang benar-benar diterapkan. Perbedaan antara yang disetujui dan yang
 * diterapkan bukan hal yang boleh hilang.
 */
class HrRequest extends HrdModel
{
    use SoftDeletes;

    protected $casts = [
        'current_values' => 'array',
        'requested_values' => 'array',
        'applied_values' => 'array',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        'completed_at' => 'datetime',
        'applied_at' => 'datetime',
        'sla_due_at' => 'datetime',
        'sla_breached_at' => 'datetime',
        'version' => 'integer',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_REVIEW = 'review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(HrRequestType::class, 'type_code', 'code');
    }

    public function items(): HasMany
    {
        return $this->hasMany(HrRequestItem::class, 'request_id');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeDocument::class, 'evidence_document_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_REVIEW, self::STATUS_APPROVED]);
    }

    public function scopeBreachingSla(Builder $query): Builder
    {
        return $query->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())->whereNull('completed_at');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_REVIEW, self::STATUS_APPROVED,
        ], true);
    }
}
