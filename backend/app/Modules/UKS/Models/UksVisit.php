<?php

namespace App\Modules\UKS\Models;

use App\Models\BaseModel;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Modules\UKS\Domain\Enums\Severity;
use App\Modules\UKS\Domain\Enums\VisitStatus;
use App\Modules\UKS\Domain\Enums\VisitType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UksVisit extends BaseModel
{
    protected $table = 'uks_visits';

    protected $casts = [
        'arrived_at' => 'datetime',
        'left_at' => 'datetime',
        'parent_notified_at' => 'datetime',
        'needs_rest' => 'boolean',
        'sent_home' => 'boolean',
        'parent_notified' => 'boolean',
        'visit_type' => VisitType::class,
        'severity' => Severity::class,
        'status' => VisitStatus::class,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function medications(): HasMany
    {
        return $this->hasMany(UksVisitMedication::class, 'visit_id');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(UksConsent::class, 'visit_id');
    }

    public function referral(): HasOne
    {
        return $this->hasOne(UksReferral::class, 'visit_id');
    }

    public function letters(): HasMany
    {
        return $this->hasMany(UksLetter::class, 'visit_id');
    }
}
