<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cermin anggaran (bukan sumber kebenaran). Tabel: `proc_budgets`.
 *
 * Disinkronkan dari BudgetProvider; kolom `source` menandai asal data sehingga
 * jelas bahwa angka ini bukan milik Procurement.
 */
class Budget extends ProcurementModel
{
    protected $table = 'proc_budgets';

    protected $casts = [
        'amount' => 'decimal:2',
        'committed' => 'decimal:2',
        'actual' => 'decimal:2',
        'synced_at' => 'datetime',
    ];

    public function commitments(): HasMany
    {
        return $this->hasMany(Commitment::class, 'budget_id');
    }

    /** Sisa anggaran yang belum dikomitkan. */
    public function available(): float
    {
        return round((float) $this->amount - (float) $this->committed - (float) $this->actual, 2);
    }
}
