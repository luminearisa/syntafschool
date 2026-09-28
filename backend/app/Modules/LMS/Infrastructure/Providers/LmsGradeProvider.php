<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Modules\LMS\Application\Services\ActivityGradeService;
use App\Modules\LMS\Domain\Contracts\GradeProviderInterface;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsActivityGrade;
use Illuminate\Support\Collection;

/**
 * Implementasi bawaan `GradeProviderInterface` (§46).
 *
 * Sengaja tipis: seluruh logika ada di `ActivityGradeService`. Phase 3
 * (Gradebook) dapat mengganti binding ini dengan implementasi yang menghitung
 * bobot, kategori nilai, atau sinkronisasi ERP tanpa mengubah modul aktivitas.
 */
class LmsGradeProvider implements GradeProviderInterface
{
    public function __construct(private readonly ActivityGradeService $grades) {}

    public function record(LmsActivity $activity, int $userId, array $data): LmsActivityGrade
    {
        return $this->grades->record($activity, $userId, $data);
    }

    public function forActivity(LmsActivity $activity): Collection
    {
        return $this->grades->forActivity($activity);
    }

    public function forStudent(int $courseId, int $userId): Collection
    {
        return $this->grades->forStudent($courseId, $userId);
    }

    public function courseSummary(int $courseId, int $userId): array
    {
        return $this->grades->courseSummary($courseId, $userId);
    }

    public function forActivityUser(LmsActivity $activity, int $userId): ?LmsActivityGrade
    {
        return $this->grades->forActivityUser($activity, $userId);
    }
}
