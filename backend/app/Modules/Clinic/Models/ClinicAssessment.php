<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Asesmen klinis berformat SOAP dengan dukungan amendment (versi).
 *
 * Tabel: `clinic_assessments`
 */
class ClinicAssessment extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_assessments';

    protected $casts = [
        'assessment_at' => 'datetime',
        'version' => 'integer',
        'is_current' => 'boolean',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }
    public function amendedFrom(): BelongsTo
    {
        return $this->belongsTo(ClinicAssessment::class, 'amended_from_id');
    }
    public function amendments(): HasMany
    {
        return $this->hasMany(ClinicAssessment::class, 'amended_from_id');
    }}
