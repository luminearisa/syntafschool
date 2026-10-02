<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventBudgetItem — tabel `event_budget_items`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventBudgetItem extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_budget_items';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'budget_id' => 'integer',
            'event_id' => 'integer',
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'planned_amount' => 'decimal:2',
            'committed_amount' => 'decimal:2',
            'actual_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'vendor_id' => 'integer',
            'procurement_required' => 'boolean',
            'procurement_request_id' => 'integer',
            'sort_order' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
    public function budget(): BelongsTo
    {
        return $this->belongsTo(EventBudget::class, 'budget_id');
    }
}
