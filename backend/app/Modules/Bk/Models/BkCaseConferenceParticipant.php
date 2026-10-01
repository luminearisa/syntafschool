<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Peserta konferensi kasus.
 *
 * Kewenangan membaca ditentukan per peserta: menghadiri konferensi tidak sama
 * dengan boleh membuka catatan konseling.
 *
 * Tabel: `bk_case_conference_participants`
 */
class BkCaseConferenceParticipant extends BkModel
{
    protected $table = 'bk_case_conference_participants';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'is_required' => 'boolean',
        'can_view_case' => 'boolean',
        'can_view_confidential' => 'boolean',
        'invited_at' => 'datetime',
        'responded_at' => 'datetime',
        'attended_at' => 'datetime',
        'participant_type' => \App\Modules\Bk\Domain\Enums\ConferenceParticipantType::class,
        'max_confidentiality' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function conference(): BelongsTo
    {
        return $this->belongsTo(BkCaseConference::class, 'conference_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'guardian_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(BkReferralProvider::class, 'external_provider_id');
    }
}
