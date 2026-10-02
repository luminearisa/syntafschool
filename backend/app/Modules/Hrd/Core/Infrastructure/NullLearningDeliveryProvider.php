<?php

namespace App\Modules\Hrd\Core\Infrastructure;

use App\Modules\Hrd\Core\Domain\Contracts\LearningDeliveryProviderInterface;

/**
 * Implementasi bawaan untuk LMS yang belum terpasang.
 *
 * Pola ini menjaga batas modul: HRD tetap berjalan penuh, peristiwanya tetap
 * tercatat, dan begitu modul tujuan tersedia cukup mengganti binding tanpa
 * mengubah satu pun layanan bisnis.
 */
class NullLearningDeliveryProvider implements LearningDeliveryProviderInterface
{
    /** @return array<string, mixed> */
    public function enroll(int $employeeId, array $courseReference, array $context = []): array
    {
        return ['enrollment_ref' => null, 'status' => 'LMS_UNAVAILABLE', 'note' => 'Modul LMS belum terpasang; peserta tetap tercatat di HRD.'];
    }

    /** @return array<string, mixed> */
    public function completion(int $assignmentId): ?array
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function courseCatalog(array $filters = []): array
    {
        return [];
    }
}
