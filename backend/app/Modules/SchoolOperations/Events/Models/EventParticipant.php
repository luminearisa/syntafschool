<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventParticipant — tabel `event_participants`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventParticipant extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_participants';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'registration_id' => 'integer',
            'source_id' => 'integer',
            'student_id' => 'integer',
            'user_id' => 'integer',
            'parent_id' => 'integer',
            'employee_id' => 'integer',
            'dormitory_id' => 'integer',
            'needs_transportation' => 'boolean',
            'needs_accommodation' => 'boolean',
            'badge_id' => 'integer',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'sessions_attended' => 'integer',
            'sessions_required' => 'integer',
            'certificate_eligible' => 'boolean',
            'certificate_id' => 'integer',
            'is_committee' => 'boolean',
            'is_volunteer' => 'boolean',
            'is_vip' => 'boolean',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }
}
