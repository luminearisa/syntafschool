<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Domain\Enums\ProcurementMethod;
use App\Modules\Procurement\Domain\Enums\PurchaseRequestPriority;
use App\Modules\Procurement\Domain\Enums\PurchaseRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Purchase Request (PR). Tabel: `proc_purchase_requests`. */
class PurchaseRequest extends ProcurementModel
{
    use SoftDeletes;

    protected $table = 'proc_purchase_requests';

    protected $casts = [
        'requested_date' => 'date',
        'required_date' => 'date',
        'estimated_total' => 'decimal:2',
        'approved_total' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'status' => PurchaseRequestStatus::class,
        'priority' => PurchaseRequestPriority::class,
        'method' => ProcurementMethod::class,
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class, 'purchase_request_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PurchaseRequestApproval::class, 'purchase_request_id')->orderBy('step');
    }

    public function eventRequests(): HasMany
    {
        return $this->hasMany(ProcurementEventRequest::class, 'purchase_request_id');
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(ProcurementEvent::class, 'proc_event_requests', 'purchase_request_id', 'event_id')
            ->withTimestamps();
    }

    /** @param Builder<self> $query */
    public function scopeStatus(Builder $query, string $status): void
    {
        $query->where('status', $status);
    }

    /** @param Builder<self> $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $like = '%'.$term.'%';
        $query->where(fn (Builder $q) => $q
            ->where('number', 'like', $like)
            ->orWhere('purpose', 'like', $like)
            ->orWhere('justification', 'like', $like));
    }

    public function isEditable(): bool
    {
        $status = $this->status instanceof PurchaseRequestStatus
            ? $this->status
            : PurchaseRequestStatus::tryFrom((string) $this->status);

        return $status?->isEditable() ?? false;
    }
}
