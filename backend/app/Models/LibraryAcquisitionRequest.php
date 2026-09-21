<?php

namespace App\Models;

use App\Models\Concerns\LogsLibraryActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pengadaan koleksi (usulan/pesanan).
 *
 * Penerimaan (`received`) dapat langsung membuat bibliographic record +
 * eksemplar sehingga koleksi baru tersedia di OPAC.
 */
class LibraryAcquisitionRequest extends BaseModel
{
    use LogsLibraryActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ORDERED = 'ordered';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_APPROVED,
        self::STATUS_ORDERED, self::STATUS_RECEIVED, self::STATUS_CANCELLED,
    ];

    /** Perpindahan status yang diizinkan. */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED => [self::STATUS_APPROVED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_ORDERED, self::STATUS_CANCELLED],
        self::STATUS_ORDERED => [self::STATUS_RECEIVED, self::STATUS_CANCELLED],
        self::STATUS_RECEIVED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $casts = [
        'requested_at' => 'date:Y-m-d',
        'expected_at' => 'date:Y-m-d',
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
        'total_amount' => 'decimal:2',
    ];

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Publisher::class, 'supplier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LibraryAcquisitionItem::class, 'request_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_RECEIVED, self::STATUS_CANCELLED]);
    }

    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function activityEventPrefix(): string
    {
        return 'ACQUISITION';
    }
}