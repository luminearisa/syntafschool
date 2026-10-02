<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Identitas tambahan pegawai (§75 employee_identifiers).
 *
 * Nomor disimpan per jenis supaya nomor sensitif dapat disamarkan secara
 * terpisah (§52) dan supaya sekolah dapat menambah jenis identitas baru tanpa
 * menambah kolom.
 */
class HrEmployeeIdentifier extends HrdModel
{
    use SoftDeletes;

    protected $casts = [
        'is_primary' => 'boolean',
        'is_verified' => 'boolean',
        'issued_date' => 'date:Y-m-d',
        'expiry_date' => 'date:Y-m-d',
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

    /** Samarkan nomor untuk pemanggil tanpa izin data sensitif (§52). */
    public function maskedNumber(): string
    {
        $number = (string) $this->number;

        if (strlen($number) <= 4) {
            return str_repeat('*', strlen($number));
        }

        return str_repeat('*', max(0, strlen($number) - 4)).substr($number, -4);
    }
}
