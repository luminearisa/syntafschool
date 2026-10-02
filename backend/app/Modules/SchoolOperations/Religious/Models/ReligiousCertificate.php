<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `religious_certificates`.
 */
class ReligiousCertificate extends Model
{
    protected $table = 'religious_certificates';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'achievement_id' => 'integer',
        'participant_id' => 'integer',
        'student_id' => 'integer',
        'employee_id' => 'integer',
        'issued_date' => 'date',
        'issued_by' => 'integer',
        'document_id' => 'integer',
        'valid_until' => 'date',
        'meta' => 'array',
    ];

    public function achievement(): BelongsTo
    {
        return $this->belongsTo(ReligiousAchievement::class, 'achievement_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivityParticipant::class, 'participant_id');
    }
}
