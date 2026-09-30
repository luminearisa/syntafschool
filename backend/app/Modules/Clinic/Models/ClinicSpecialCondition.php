<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\SpecialConditionCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kondisi khusus: kronis, perawatan khusus, pantangan makanan, pembatasan aktivitas.
 *
 * Tabel: `clinic_special_conditions`
 */
class ClinicSpecialCondition extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_special_conditions';

    use SoftDeletes;

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'visible_to_kitchen' => 'boolean',
        'visible_to_boarding' => 'boolean',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
    public function categoryEnum(): SpecialConditionCategory
    {
        return SpecialConditionCategory::from($this->category);
    }

    /** Apakah boleh dibaca dapur/asrama — hanya instruksi, tanpa diagnosis. */
    public function isOperationallyVisible(): bool
    {
        return $this->visible_to_kitchen || $this->visible_to_boarding;
    }
}
