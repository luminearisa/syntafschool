<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Jejak audit aktivitas — append-only; tidak ada endpoint yang menyunting atau menghapusnya.
 *
 * Tabel: `activity_audit_logs`
 */
class ActivityAuditLog extends ActivityModel
{
    /**
     * Tabel riwayat append-only: hanya `created_at` yang dibutuhkan (diisi basis
     * data). Tanpa ini Eloquent akan menulis `updated_at` yang memang tidak ada
     * — dan baris riwayat tidak boleh punya arti "diperbarui".
     */
    public $timestamps = false;

    protected $table = 'activity_audit_logs';

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];
    public function actor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'actor_user_id');
    }
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
}
