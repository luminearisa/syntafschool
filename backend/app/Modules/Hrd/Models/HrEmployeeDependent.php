<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Keluarga & tanggungan (§27).
 *
 * Data ini yang nantinya dibaca Benefits/BPJS/Payroll: "siapa saja tanggungan
 * yang masih aktif?" Karena itu status tanggungan dan penanda penerima manfaat
 * disimpan eksplisit, bukan disimpulkan dari tanggal lahir.
 */
class HrEmployeeDependent extends HrdModel
{
    use SoftDeletes;

    protected $casts = [
        'birth_date' => 'date:Y-m-d',
        'is_beneficiary' => 'boolean',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopeActive($query)
    {
        return $query->where('dependent_status', 'active');
    }

    public function age(): ?int
    {
        return $this->birth_date?->age;
    }
}
