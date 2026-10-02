<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Riwayat pendidikan (§28).
 *
 * `is_highest` menandai pendidikan terakhir dan `verified` menandai ijazah yang
 * sudah dicocokkan dengan berkas aslinya — dua hal berbeda yang menentukan
 * kelayakan tunjangan pendidikan dan pemenuhan syarat jabatan.
 */
class HrEmployeeEducation extends HrdModel
{
    use SoftDeletes;

    protected $casts = [
        'start_year' => 'integer',
        'end_year' => 'integer',
        'gpa' => 'decimal:2',
        'is_highest' => 'boolean',
        'verified' => 'boolean',
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

    public function document(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeDocument::class, 'document_id');
    }
}
