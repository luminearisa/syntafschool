<?php

namespace App\Modules\Hrd\Core\Infrastructure;

use App\Modules\Hrd\Core\Domain\Contracts\SurveyProviderInterface;

/**
 * Implementasi bawaan untuk Survey & Feedback ERP yang belum terpasang.
 *
 * Pola ini menjaga batas modul: HRD tetap berjalan penuh, peristiwanya tetap
 * tercatat, dan begitu modul tujuan tersedia cukup mengganti binding tanpa
 * mengubah satu pun layanan bisnis.
 */
class NullSurveyProvider implements SurveyProviderInterface
{
    /** @return array<string, mixed> */
    public function publish(array $survey, int $actorUserId): array
    {
        return ['reference' => null, 'status' => 'SURVEY_MODULE_UNAVAILABLE'];
    }

    /** @return array<string, mixed> */
    public function responses(string $surveyReference, array $filters = []): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function aggregate(string $surveyReference): array
    {
        return ['responses' => 0, 'score' => null, 'note' => 'Modul survei ERP belum terpasang.'];
    }
}
