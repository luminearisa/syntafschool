<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Log notifikasi — hanya ringkasan aman, tidak pernah isi catatan konseling.
 *
 * Tabel: `bk_notification_logs`
 */
class BkNotificationLog extends BkModel
{
    protected $table = 'bk_notification_logs';

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
        'event' => \App\Modules\Bk\Domain\Enums\NotificationEvent::class,
    ];

    public function notifiable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notifiable_user_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}
