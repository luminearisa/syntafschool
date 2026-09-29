<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

/**
 * Jejak pengiriman pemberitahuan LMS (§10, §41).
 *
 * `dedupe_key` unik adalah penegak idempotensi: job/permintaan yang sama tidak
 * mengirim notifikasi dua kali.
 */
class LmsNotificationLog extends BaseModel
{
    protected $table = 'lms_notification_logs';

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
        'recipients_count' => 'integer',
        'sent_count' => 'integer',
        'attempts' => 'integer',
    ];
}
