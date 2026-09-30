<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Batch obat beserta kedaluwarsa (dasar FEFO).
 *
 * Tabel: `clinic_medication_batches`
 */
class ClinicMedicationBatch extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_medication_batches';

    protected $casts = [
        'manufacture_date' => 'date',
        'expiry_date' => 'date',
        'quantity_received' => 'decimal:2',
    ];

    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClinicMedication::class, 'medication_id');
    }
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    public function isExpiringSoon(int $days = 60): bool
    {
        return $this->expiry_date !== null
            && ! $this->isExpired()
            && $this->expiry_date->lessThanOrEqualTo(now()->addDays($days));
    }

    /** Batch yang boleh dipakai: status tersedia dan belum kedaluwarsa (urut FEFO). */
    public function scopeUsable(Builder $query): void
    {
        $query->where('status', 'AVAILABLE')
            ->where(fn (Builder $q) => $q->whereNull('expiry_date')
                ->orWhereDate('expiry_date', '>=', now()->toDateString()))
            ->orderBy('expiry_date');
    }
}
