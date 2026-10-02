<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EventProgramItem — tabel `event_program_items`.
 *
 * Relasi hanya dibentuk untuk rujukan di dalam modul Event. Kolom yang
 * menunjuk modul lain diakses lewat kontrak/gateway, bukan relasi Eloquent.
 */
class EventProgramItem extends Model
{
    use SoftDeletes;

    /** Tabel yang dipetakan (disebut eksplisit agar aman terhadap bentuk jamak tak beraturan). */
    protected $table = 'event_program_items';
    use SoftDeletes;
    /** Kolom yang boleh diisi massal. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'program_id' => 'integer',
            'event_id' => 'integer',
            'session_id' => 'integer',
            'sequence' => 'integer',
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
            'duration_minutes' => 'integer',
            'speaker_id' => 'integer',
            'responsible_user_id' => 'integer',
            'venue_id' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'delay_minutes' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
    public function program(): BelongsTo
    {
        return $this->belongsTo(EventProgram::class, 'program_id');
    }

    public function programItems(): HasMany
    {
        return $this->hasMany(EventProgramDependency::class, 'program_item_id');
    }
}
