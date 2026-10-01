<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\WaitlistStatus;

/**
 * Antrean daftar tunggu; promosi keanggotaan saat ada lowongan bersifat idempoten.
 *
 * Tabel: `activity_waitlist_entries`
 */
class ActivityWaitlistEntry extends ActivityModel
{
    protected $table = 'activity_waitlist_entries';

    protected $casts = [
        'status' => WaitlistStatus::class,
        'promoted_at' => 'datetime',
        'position' => 'integer',
    ];
    public function registration(): BelongsTo
    {
        return $this->belongsTo(ActivityRegistration::class, 'registration_id');
    }
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
    public function promotedMembership(): BelongsTo
    {
        return $this->belongsTo(ActivityMembership::class, 'promoted_membership_id');
    }
}
