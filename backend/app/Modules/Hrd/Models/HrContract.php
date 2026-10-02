<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kontrak kerja (§36).
 *
 * Perpanjangan TIDAK menimpa kontrak lama: `supersedes_contract_id` menaut kontrak
 * baru ke kontrak yang digantikannya, sehingga riwayat tanda tangan tetap utuh
 * dan pertanyaan "berapa kali kontrak ini diperpanjang?" terjawab.
 */
class HrContract extends HrdModel
{
    use SoftDeletes;

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'renewal_date' => 'date:Y-m-d',
        'signed_date' => 'date:Y-m-d',
        'is_signed' => 'boolean',
        'reminder_sent_at' => 'datetime',
        'version' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(HrContractType::class, 'contract_type_id');
    }

    public function employmentRecord(): BelongsTo
    {
        return $this->belongsTo(HrEmploymentRecord::class, 'employment_record_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_contract_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeDocument::class, 'document_id');
    }

    public function scopeActive($query): Builder
    {
        return $query->whereIn('status', ['active', 'expiring']);
    }

    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    public function daysUntilEnd(): ?int
    {
        return $this->end_date?->isFuture() ? (int) now()->startOfDay()->diffInDays($this->end_date, false) : null;
    }
}
