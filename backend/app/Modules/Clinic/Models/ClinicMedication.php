<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use App\Models\InventoryItem;
use App\Modules\Clinic\Domain\Enums\DosageForm;
use App\Modules\Clinic\Domain\Enums\MedicationRoute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master obat klinis. Saldo stok TIDAK disimpan di sini — saldo ada di `inventory_items.quantity` dan setiap pergerakan lewat InventoryProviderInterface.
 *
 * Tabel: `clinic_medications`
 */
class ClinicMedication extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_medications';

    use SoftDeletes;

    protected $casts = [
        'requires_prescription' => 'boolean',
        'is_controlled' => 'boolean',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
    public function batches(): HasMany
    {
        return $this->hasMany(ClinicMedicationBatch::class, 'medication_id');
    }
    public function prescriptionItems(): HasMany
    {
        return $this->hasMany(ClinicPrescriptionItem::class, 'medication_id');
    }
    public function administrations(): HasMany
    {
        return $this->hasMany(ClinicMedicationAdministration::class, 'medication_id');
    }
    public function dispensingItems(): HasMany
    {
        return $this->hasMany(ClinicDispensingItem::class, 'medication_id');
    }
    public function allergies(): HasMany
    {
        return $this->hasMany(ClinicAllergy::class, 'medication_id');
    }
    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'ACTIVE');
    }

    /** True bila obat dipetakan ke item Inventory (syarat stok bisa dikurangi). */
    public function isLinkedToInventory(): bool
    {
        return $this->inventory_item_id !== null;
    }

    public function formEnum(): DosageForm
    {
        return DosageForm::from($this->dosage_form);
    }

    public function routeEnum(): MedicationRoute
    {
        return MedicationRoute::from($this->route);
    }
}
