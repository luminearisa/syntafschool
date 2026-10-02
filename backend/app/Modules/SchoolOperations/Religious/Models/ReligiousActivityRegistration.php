<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `religious_activity_registrations`.
 */
class ReligiousActivityRegistration extends Model
{
    protected $table = 'religious_activity_registrations';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'activity_id' => 'integer',
        'session_id' => 'integer',
        'student_id' => 'integer',
        'employee_id' => 'integer',
        'parent_id' => 'integer',
        'registered_at' => 'datetime',
        'waitlist_position' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivity::class, 'activity_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReligiousActivitySession::class, 'session_id');
    }

    public function religiousActivityParticipants(): HasMany
    {
        return $this->hasMany(ReligiousActivityParticipant::class, 'registration_id');
    }
}
