<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Peminjaman aset (proyektor, kamera, alat) beserta pengembaliannya.
 */
class AssetLoan extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_loans';

    protected $fillable = [
        'loan_number',
        'status',
        'requested_by',
        'borrower_id',
        'borrower_type',
        'unit_id',
        'purpose',
        'activity_reference',
        'start_at',
        'due_at',
        'returned_at',
        'approved_by',
        'approved_at',
        'handed_over_at',
        'handed_over_by',
        'received_by',
        'condition_out',
        'condition_in',
        'accessories',
        'damage_report_id',
        'incident_id',
        'overdue_notified_at',
        'notes',
        'borrower_id',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\LoanStatus::class,
        'start_at' => 'datetime',
        'due_at' => 'datetime',
        'returned_at' => 'datetime',
        'approved_at' => 'datetime',
        'handed_over_at' => 'datetime',
        'accessories' => 'array',
        'overdue_notified_at' => 'datetime',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetLoanItem::class, 'loan_id');
    }

    public function assets(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_loan_items', 'loan_id', 'asset_id')
            ->withPivot(['condition_out', 'condition_in', 'returned']);
    }

    public function borrower(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'borrower_id');
    }

    public function requester(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by');
    }

    public function approver(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }

    public function returnRecord(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(AssetReturn::class, 'loan_id');
    }

    public function isOverdue(): bool
    {
        return $this->status instanceof \App\Modules\Asset\Domain\Enums\LoanStatus
            && in_array($this->status, [\App\Modules\Asset\Domain\Enums\LoanStatus::ACTIVE, \App\Modules\Asset\Domain\Enums\LoanStatus::OVERDUE], true)
            && $this->due_at !== null
            && $this->due_at->isPast();
    }
}
