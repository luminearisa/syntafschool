<?php

namespace App\Modules\Bk\Notifications;

use App\Modules\Bk\Application\Services\BkNotificationService;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Models\BkEarlyWarningRule;

/**
 * Pemberitahuan hasil mesin peringatan dini.
 *
 * Isi pemberitahuan sengaja hanya menyebut **metrik dan ambang**, bukan tafsir
 * atas kondisi siswa: pemberitahuan dapat terbaca di layar kunci perangkat, dan
 * tafsir klinis tidak boleh muncul di sana.
 */
class BkEarlyWarningNotification
{
    public static function send(int $userId, BkEarlyWarningRule $rule, int $studentId, float $observed, ?int $indicatorId = null): void
    {
        app(BkNotificationService::class)->notifyUser(
            $userId,
            NotificationEvent::BK_REVIEW_REQUIRED,
            "Tinjauan BK: {$rule->name}",
            [
                'rule_code' => $rule->code,
                'metric' => $rule->metric->value,
                'observed' => $observed,
                'threshold' => (float) $rule->threshold,
                'window_days' => (int) $rule->window_days,
                'student_id' => $studentId,
                'indicator_id' => $indicatorId,
                'note' => 'Peringatan berbasis ambang, bukan diagnosis. Mohon ditinjau manusia.',
            ],
        );
    }
}
