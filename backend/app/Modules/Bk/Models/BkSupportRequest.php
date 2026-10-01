<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\SupportRequestType;
use App\Modules\Bk\Domain\Enums\SupportRequestStatus;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Permintaan dukungan dari portal siswa/orang tua.

Formulirnya sengaja ringan: siswa/orang tua hanya menuliskan **topik** dan waktu
yang disukai — bukan detail sensitif — karena detail akan dibahas langsung dengan
konselor.
 *
 * Tabel: `bk_support_requests`
 */
class BkSupportRequest extends BkModel
{
    protected $table = 'bk_support_requests';

    use SoftDeletes;

    protected $casts = [
        'urgent' => 'boolean',
        'reviewed_at' => 'datetime',
        'request_type' => \App\Modules\Bk\Domain\Enums\SupportRequestType::class,
        'status' => \App\Modules\Bk\Domain\Enums\SupportRequestStatus::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'guardian_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(BkReferral::class, 'referral_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function scopeQueued(Builder $query): Builder
    {
        return $query->whereIn('status', ['QUEUED', 'REVIEWED', 'ASSIGNED', 'SCHEDULED']);
    }
}
