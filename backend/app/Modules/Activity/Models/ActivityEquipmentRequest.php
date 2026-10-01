<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\EquipmentRequestStatus;

/**
 * Permintaan peralatan/seragam ke Inventory atau Aset, termasuk pengembaliannya.
 *
 * Tabel: `activity_equipment_requests`
 */
class ActivityEquipmentRequest extends ActivityModel
{
    protected $table = 'activity_equipment_requests';

    protected $casts = [
        'status' => EquipmentRequestStatus::class,
        'due_on' => 'date',
        'issued_at' => 'datetime',
        'returned_at' => 'datetime',
        'quantity' => 'integer',
        'issued_quantity' => 'integer',
        'returned_quantity' => 'integer',
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(\App\Models\InventoryItem::class, 'inventory_item_id');
    }
    public function requester(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by');
    }
}
