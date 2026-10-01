<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\MaterialType;

/**
 * Materi sesi: dokumen, tautan, video, presentasi, atau rujukan materi LMS.
 *
 * Tabel: `activity_session_materials`
 */
class ActivitySessionMaterial extends ActivityModel
{
    protected $table = 'activity_session_materials';

    protected $casts = [
        'type' => MaterialType::class,
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function session(): BelongsTo
    {
        return $this->belongsTo(ActivitySession::class, 'session_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
