<?php

namespace App\Modules\LMS\Observers;

use App\Modules\LMS\Application\Services\GradeItemService;
use App\Modules\LMS\Domain\Enums\ActivityStatus;
use App\Modules\LMS\Models\LmsActivity;
use Throwable;

/**
 * Setiap aktivitas bernilai yang terbit otomatis mendapat grade item (§3, §64).
 *
 * Observer tidak pernah menggagalkan penerbitan aktivitas: bila gradebook tidak
 * dapat disiapkan (mis. course sangat lama), guru masih bisa menyinkronkan
 * manual dari halaman gradebook.
 */
class LmsActivityObserver
{
    public function __construct(private readonly GradeItemService $items) {}

    public function saved(LmsActivity $activity): void
    {
        if (! $activity->is_graded || $activity->status !== ActivityStatus::PUBLISHED) {
            return;
        }

        try {
            $this->items->ensureForActivity($activity, $activity->created_by === null ? null : (int) $activity->created_by);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
