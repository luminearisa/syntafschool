<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Master jenis tindakan klinis (dapat dikonfigurasi).
 *
 * Tabel: `clinic_procedure_types`
 */
class ClinicProcedureType extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_procedure_types';

    protected $casts = [
        'requires_consent' => 'boolean',
        'is_billable' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function procedures(): HasMany
    {
        return $this->hasMany(ClinicProcedure::class, 'type_id');
    }}
