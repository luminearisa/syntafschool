<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Domain\Contracts;

use App\Modules\Hrd\Strategic\Ai\Models\HrAiRecommendation;

/**
 * Rekomendasi HR yang dapat ditinjau manusia dan diteruskan ke workflow.
 *
 * Kontrak ini yang dipakai modul lain dan AI; implementasinya didaftarkan di
 * HrdPhase3ServiceProvider. Mengganti implementasi tidak menyentuh layanan.
 */
interface HRRecommendationProviderInterface
{
    public function recommend(array $context, mixed $user): HrAiRecommendation;

    /** @return array<string, mixed> */
    public function convertToAction(int $recommendationId, mixed $user): array;

    public function reviewRecommendation(int $recommendationId, string $decision, mixed $user, ?string $notes = null): HrAiRecommendation;
}
