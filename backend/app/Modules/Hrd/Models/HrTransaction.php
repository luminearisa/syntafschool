<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Transaksi SDM (§33–§35, §68, §77).
 *
 * Perubahan organisasi TIDAK PERNAH dilakukan dengan menyunting pegawai secara
 * langsung: semuanya lewat baris ini, yang punya status, persetujuan, tanggal
 * berlaku, dan jejak sebelum/sesudah.
 *
 * `idempotency_key` menahan pemrosesan ganda ketika klien mengirim ulang
 * permintaan yang sama; `status` bergerak lewat state machine, bukan ditulis
 * bebas dari controller.
 */
class HrTransaction extends HrdModel
{
    use SoftDeletes;

    protected $casts = [
        'effective_date' => 'date:Y-m-d',
        'payload' => 'array',
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'applied_at' => 'datetime',
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
        return $this->belongsTo(HrTransactionType::class, 'type_code', 'code');
    }

    public function items(): HasMany
    {
        return $this->hasMany(HrTransactionItem::class, 'transaction_id')->orderBy('sort_order');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(HrEmployeeStatusHistory::class, 'transaction_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_REVIEW, self::STATUS_APPROVED]);
    }

    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_REVIEW]);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_REVIEW, self::STATUS_APPROVED,
        ], true);
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }
}
