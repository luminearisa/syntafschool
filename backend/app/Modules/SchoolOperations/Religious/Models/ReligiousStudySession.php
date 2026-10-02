<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `religious_study_sessions`.
 */
class ReligiousStudySession extends Model
{
    protected $table = 'religious_study_sessions';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'activity_id' => 'integer',
        'session_id' => 'integer',
        'personnel_id' => 'integer',
        'page_from' => 'integer',
        'page_to' => 'integer',
        'reference_id' => 'integer',
        'session_number' => 'integer',
        'session_date' => 'date',
        'duration_minutes' => 'integer',
        'meta' => 'array',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivity::class, 'activity_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivitySession::class, 'session_id');
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(ReligiousPersonnelProfile::class, 'personnel_id');
    }

    public function reference(): BelongsTo
    {
        return $this->belongsTo(ReligiousStudyReference::class, 'reference_id');
    }
}
