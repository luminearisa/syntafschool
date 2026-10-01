<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Modules\Activity\Domain\Enums\AchievementLevel;
use App\Modules\Activity\Domain\Enums\AchievementOwnership;
use App\Modules\Activity\Domain\Enums\AchievementRank;
use App\Modules\Activity\Domain\Enums\AchievementType;
use App\Modules\Activity\Domain\Enums\VerificationStatus;

/**
 * Prestasi siswa/tim — entitas tersendiri, bukan catatan tempelan pada program.
 *
 * Tabel: `activity_achievements`
 */
class ActivityAchievement extends ActivityModel
{
    protected $table = 'activity_achievements';

    use SoftDeletes;

    protected $casts = [
        'type' => AchievementType::class,
        'ownership' => AchievementOwnership::class,
        'level' => AchievementLevel::class,
        'rank' => AchievementRank::class,
        'awarded_date' => 'date',
        'evidence_files' => 'array',
        'verification_status' => VerificationStatus::class,
        'verified_at' => 'datetime',
        'counts_for_portfolio' => 'boolean',
        'metadata' => 'array',
    ];
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
    public function team(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionTeam::class, 'team_id');
    }
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function competition(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetition::class, 'competition_id');
    }
    public function result(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetitionResult::class, 'result_id');
    }
    public function achievementType(): BelongsTo
    {
        return $this->belongsTo(\App\Models\AchievementType::class, 'achievement_type_id');
    }
    public function members(): HasMany
    {
        return $this->hasMany(ActivityAchievementMember::class, 'achievement_id');
    }
    public function certificates(): HasMany
    {
        return $this->hasMany(ActivityCertificate::class, 'achievement_id');
    }
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'verified_by');
    }
}
