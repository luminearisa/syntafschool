<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Riwayat amendment catatan klinis penting: nilai sebelumnya, nilai baru, alasan, pelaku.
 *
 * Tabel: `clinic_record_amendments`
 */
class ClinicRecordAmendment extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_record_amendments';

    protected $casts = [
        'previous_value' => 'array',
        'new_value' => 'array',
        'changed_at' => 'datetime',
        'version' => 'integer',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }}
