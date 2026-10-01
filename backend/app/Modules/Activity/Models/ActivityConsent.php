<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\ConsentScope;
use App\Modules\Activity\Domain\Enums\ConsentStatus;

/**
 * Persetujuan orang tua — hanya menyetujui/menolak, tanpa hak mengubah data aktivitas.
 *
 * Tabel: `activity_consents`
 */
class ActivityConsent extends ActivityModel
{
    protected $table = 'activity_consents';

    protected $casts = [
        'decision' => ConsentStatus::class,
        'scope' => ConsentScope::class,
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
        'expires_at' => 'datetime',
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
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Guardian::class, 'guardian_id');
    }
    public function parentUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'parent_user_id');
    }
}
