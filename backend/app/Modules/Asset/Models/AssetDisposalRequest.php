<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Permintaan penghapusan aset — tidak pernah langsung menghapus baris aset.
 */
class AssetDisposalRequest extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_disposal_requests';

    protected $fillable = [
        'request_number',
        'asset_id',
        'reason',
        'method',
        'status',
        'requested_by',
        'description',
        'condition_at_request',
        'book_value_at_request',
        'estimated_value',
        'expected_proceeds',
        'inspection_id',
        'valuation_id',
        'buyer_name',
        'donation_recipient',
        'approval_reference',
        'submitted_at',
        'approved_by',
        'approved_at',
        'executed_at',
        'executed_by',
        'finance_reference',
        'finance_status',
        'retired_at',
        'document_reference',
        'rejection_reason',
        'notes',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\DisposalStatus::class,
        'reason' => \App\Modules\Asset\Domain\Enums\DisposalReason::class,
        'method' => \App\Modules\Asset\Domain\Enums\DisposalMethod::class,
        'estimated_value' => 'decimal:2',
        'expected_proceeds' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function requester(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by');
    }

    public function approver(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }

    public function disposal(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(AssetDisposal::class, 'disposal_request_id');
    }

    public function inspection(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AssetInspection::class, 'inspection_id');
    }
}
