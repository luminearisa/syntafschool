<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tindakan yang dikerjakan pada sebuah kunjungan.
 *
 * Tabel: `clinic_procedures`
 */
class ClinicProcedure extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_procedures';

    protected $casts = [
        'performed_at' => 'datetime',
        'supplies' => 'array',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function type(): BelongsTo
    {
        return $this->belongsTo(ClinicProcedureType::class, 'type_id');
    }
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }}
