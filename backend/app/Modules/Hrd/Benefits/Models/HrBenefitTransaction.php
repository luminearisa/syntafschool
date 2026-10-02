<?php

namespace App\Modules\Hrd\Benefits\Models;

use App\Modules\Hrd\Models\HrdModel;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_benefit_transactions` (domain Benefits).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrBenefitTransaction extends HrdModel
{
    protected $table = 'hr_benefit_transactions';

    protected $guarded = [];
    protected $casts = [
        'effective_date' => 'date:Y-m-d',
        'amount' => 'decimal:2',
        'payload' => 'array',
    ];

    /** Pegawai pemilik baris ini. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
