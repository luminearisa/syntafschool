<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\ScreeningOutcome;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Screening awal — penilaian situasi sebelum kasus ditangani.

Screening bukan diagnosis: ia merangkum sudut pandang siswa, guru, dan orang tua
serta indikator akademik/kehadiran/perilaku/asrama, lalu merekomendasikan langkah.
 *
 * Tabel: `bk_screenings`
 */
class BkScreening extends BkModel
{
    protected $table = 'bk_screenings';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'screened_at' => 'datetime',
        'follow_up_date' => 'date',
        'immediate_support_needed' => 'boolean',
        'academic_indicators' => 'array',
        'attendance_indicators' => 'array',
        'behavioral_indicators' => 'array',
        'boarding_indicators' => 'array',
        'recommended_outcome' => \App\Modules\Bk\Domain\Enums\ScreeningOutcome::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(BkReferral::class, 'referral_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function screenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'screened_by');
    }
}
