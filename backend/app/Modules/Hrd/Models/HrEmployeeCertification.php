<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sertifikat milik pegawai (§29).
 *
 * `status` (`valid` / `expiring` / `expired`) dihitung ulang oleh penjadwal harian
 * dari `expiry_date`, jadi tidak ada sertifikat kedaluwarsa yang tetap terbaca
 * "valid" hanya karena tidak ada yang memperbarui datanya.
 */
class HrEmployeeCertification extends HrdModel
{
    use SoftDeletes;

    protected $casts = [
        'issued_date' => 'date:Y-m-d',
        'expiry_date' => 'date:Y-m-d',
        'verified' => 'boolean',
        'verified_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function certification(): BelongsTo
    {
        return $this->belongsTo(HrCertification::class, 'certification_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeDocument::class, 'document_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }
}
