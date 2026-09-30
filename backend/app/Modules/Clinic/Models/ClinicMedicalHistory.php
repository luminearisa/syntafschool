<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\HistoryCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Riwayat medis pasien per kategori.
 *
 * Tabel: `clinic_medical_histories`
 */
class ClinicMedicalHistory extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_medical_histories';

    use SoftDeletes;

    protected $casts = [
        'recorded_at' => 'date',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
    public function categoryEnum(): HistoryCategory
    {
        return HistoryCategory::from($this->category);
    }
}
