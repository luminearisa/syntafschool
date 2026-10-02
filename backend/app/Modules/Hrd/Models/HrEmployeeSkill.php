<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keterampilan pegawai (§30, §84).
 *
 * `proficiency_level_code` + `last_assessed_at` adalah dua kolom yang membuat
 * HR berbasis keterampilan mungkin: tanpa tanggal penilaian terakhir, "advanced"
 * hasil kursus 2019 tidak dapat dibedakan dari yang baru diuji bulan lalu.
 */
class HrEmployeeSkill extends HrdModel
{
    protected $casts = [
        'years_experience' => 'decimal:1',
        'verified' => 'boolean',
        'verified_at' => 'datetime',
        'last_assessed_at' => 'date:Y-m-d',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(HrSkill::class, 'skill_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
