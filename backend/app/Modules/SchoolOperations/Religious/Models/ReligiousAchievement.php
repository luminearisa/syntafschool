<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `religious_achievements`.
 */
class ReligiousAchievement extends Model
{
    protected $table = 'religious_achievements';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'student_id' => 'integer',
        'employee_id' => 'integer',
        'participant_id' => 'integer',
        'score' => 'decimal:2',
        'activity_id' => 'integer',
        'assessment_id' => 'integer',
        'achievement_date' => 'date',
        'student_activity_id' => 'integer',
        'created_by' => 'integer',
        'meta' => 'array',
    ];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivityParticipant::class, 'participant_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivity::class, 'activity_id');
    }

    public function religiousCertificates(): HasMany
    {
        return $this->hasMany(ReligiousCertificate::class, 'achievement_id');
    }
}
