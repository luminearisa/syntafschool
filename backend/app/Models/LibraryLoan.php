<?php

namespace App\Models;

use App\Models\Concerns\LogsLibraryActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Peminjaman satu eksemplar oleh satu anggota.
 *
 * Satu eksemplar hanya boleh memiliki satu pinjaman aktif (dijaga
 * `CirculationService`). Status `overdue` ditetapkan scheduler harian —
 * bukan dihitung ulang tiap request — sehingga laporan tetap konsisten.
 */
class LibraryLoan extends BaseModel
{
    use LogsLibraryActivity, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_LOST = 'lost';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_RETURNED,
        self::STATUS_OVERDUE,
        self::STATUS_LOST,
    ];

    /** Status yang masih "di tangan peminjam". */
    public const OPEN_STATUSES = [self::STATUS_ACTIVE, self::STATUS_OVERDUE];

    protected $casts = [
        'borrowed_at' => 'date:Y-m-d',
        'due_at' => 'date:Y-m-d',
        'returned_on' => 'date:Y-m-d',
        'returned_at' => 'datetime',
        'renewed_count' => 'integer',
    ];

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(LibraryMember::class, 'member_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LibraryItem::class, 'item_id');
    }

    public function bibliography(): BelongsTo
    {
        return $this->belongsTo(LibraryBibliography::class, 'bibliography_id');
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(LibraryLoanRenewal::class, 'loan_id')->orderBy('id');
    }

    public function fines(): HasMany
    {
        return $this->hasMany(LibraryFine::class, 'loan_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isOverdue(?Carbon $date = null): bool
    {
        $date ??= Carbon::now();

        return $this->isOpen() && $this->due_at !== null && $this->due_at->isBefore($date->startOfDay());
    }

    /** Hari keterlambatan pada tanggal tertentu (0 bila belum lewat). */
    public function overdueDays(?Carbon $date = null): int
    {
        $date ??= Carbon::now();

        if ($this->due_at === null || ! $this->due_at->isBefore($date->startOfDay())) {
            return 0;
        }

        return (int) $this->due_at->startOfDay()->diffInDays($date->copy()->startOfDay());
    }

    public function activityEventPrefix(): string
    {
        return 'LOAN';
    }
}