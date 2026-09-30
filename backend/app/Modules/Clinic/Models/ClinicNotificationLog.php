<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\NotificationEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * Log kebijakan notifikasi: apa yang dikirim, ke siapa, dan apa yang sengaja ditahan.
 *
 * Tabel: `clinic_notification_logs`
 */
class ClinicNotificationLog extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_notification_logs';

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
    public function eventEnum(): NotificationEvent
    {
        return NotificationEvent::from($this->event);
    }
}
