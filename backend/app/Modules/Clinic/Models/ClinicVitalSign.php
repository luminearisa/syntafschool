<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tanda vital serial; dipakai untuk grafik riwayat pasien.
 *
 * Tabel: `clinic_vital_signs`
 */
class ClinicVitalSign extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_vital_signs';

    protected $casts = [
        'temperature' => 'decimal:1',
        'systolic' => 'integer',
        'diastolic' => 'integer',
        'heart_rate' => 'integer',
        'respiratory_rate' => 'integer',
        'oxygen_saturation' => 'integer',
        'pain_score' => 'integer',
        'weight_kg' => 'decimal:2',
        'height_cm' => 'decimal:2',
        'bmi' => 'decimal:2',
        'blood_glucose' => 'decimal:2',
        'measured_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function measuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'measured_by');
    }
    /** Klasifikasi IMT ringkas — indikatif untuk skrining, bukan diagnosis. */
    public static function classifyBmi(float $bmi): string
    {
        return match (true) {
            $bmi < 17.0 => 'KURUS',
            $bmi < 18.5 => 'KURANG',
            $bmi < 25.0 => 'NORMAL',
            $bmi < 27.0 => 'LEBIH',
            default => 'OBESITAS',
        };
    }

    /** Rata-rata dua tekanan darah sebagai angka tunggal untuk grafik tren. */
    public function meanArterialPressure(): ?float
    {
        if ($this->systolic === null || $this->diastolic === null) {
            return null;
        }

        return round(((float) $this->systolic + 2 * (float) $this->diastolic) / 3, 1);
    }
}
