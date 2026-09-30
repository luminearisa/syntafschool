<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\InventoryMovement;
use App\Modules\Clinic\Domain\Enums\InventoryStockResult;
use Illuminate\Database\Eloquent\Builder;

/**
 * Baris penyerahan obat beserta hasil pemanggilan InventoryProviderInterface.
 *
 * Tabel: `clinic_dispensing_items`
 */
class ClinicDispensingItem extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_dispensing_items';

    protected $casts = [
        'quantity' => 'decimal:2',
        'expiry_date' => 'date',
    ];

    public function dispensing(): BelongsTo
    {
        return $this->belongsTo(ClinicDispensing::class, 'dispensing_id');
    }
    public function prescriptionItem(): BelongsTo
    {
        return $this->belongsTo(ClinicPrescriptionItem::class, 'prescription_item_id');
    }
    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClinicMedication::class, 'medication_id');
    }
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ClinicMedicationBatch::class, 'batch_id');
    }
    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }
    public function resultEnum(): InventoryStockResult
    {
        return InventoryStockResult::from($this->inventory_result);
    }

    public function wasStocked(): bool
    {
        return $this->resultEnum() === InventoryStockResult::SUCCESS;
    }
}
