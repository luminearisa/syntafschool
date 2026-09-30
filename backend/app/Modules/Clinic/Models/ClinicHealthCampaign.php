<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Edukasi & kampanye kesehatan (dapat ditautkan ke LMS).
 *
 * Tabel: `clinic_health_campaigns`
 */
class ClinicHealthCampaign extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_health_campaigns';

    use SoftDeletes;

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }}
