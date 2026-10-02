<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Domain\Contracts;

use App\Modules\Hrd\Strategic\Ai\Models\HrAiRecommendation;

/**
 * AI HR Assistant & agent: tanya-jawab, rekomendasi, audit, human-in-the-loop.
 *
 * Kontrak ini yang dipakai modul lain dan AI; implementasinya didaftarkan di
 * HrdPhase3ServiceProvider. Mengganti implementasi tidak menyentuh layanan.
 */
interface AIHRProviderInterface
{
    /** @return array<string, mixed> */
    public function ask(array $request, mixed $user, string $channel = 'COPILOT'): array;

    /** @return array<int, array<string, mixed>> */
    public function agents(): array;

    public function recommend(array $context, mixed $user): HrAiRecommendation;

    public function reviewRecommendation(int $recommendationId, string $decision, mixed $user, ?string $notes = null): HrAiRecommendation;

    /** @return array<string, mixed> */
    public function runs(int $limit = 20): array;
}
