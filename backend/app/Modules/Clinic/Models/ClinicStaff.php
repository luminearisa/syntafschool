<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\StaffRole;
use Illuminate\Database\Eloquent\Builder;

/**
 * Penugasan petugas di sebuah lokasi layanan; menentukan wewenang klinis.
 *
 * Tabel: `clinic_staff`
 */
class ClinicStaff extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_staff';

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(ClinicLocation::class, 'clinic_id');
    }
    public function roleEnum(): StaffRole
    {
        return StaffRole::from($this->role);
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE'
            && ($this->end_date === null || $this->end_date->isFuture());
    }
}
