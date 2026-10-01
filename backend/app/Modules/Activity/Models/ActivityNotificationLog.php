<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\NotificationStatus;

/**
 * Catatan pengiriman notifikasi beserta kunci pencegah pengiriman ganda.
 *
 * Tabel: `activity_notification_logs`
 */
class ActivityNotificationLog extends ActivityModel
{
    /**
     * Tabel riwayat append-only: hanya `created_at` yang dibutuhkan (diisi basis
     * data). Tanpa ini Eloquent akan menulis `updated_at` yang memang tidak ada
     * — dan baris riwayat tidak boleh punya arti "diperbarui".
     */
    public $timestamps = false;

    protected $table = 'activity_notification_logs';

    protected $casts = [
        'status' => NotificationStatus::class,
        'payload' => 'array',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
    ];
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'recipient_user_id');
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
