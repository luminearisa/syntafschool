<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Domain\Contracts;

/** Strategy boundary for scoring normalization and multi-judge aggregation. */
interface ScoringProviderInterface extends ProviderAvailabilityInterface
{
    public function weightedCriterion(float $raw, float $weight, float $minimum, float $maximum): float;

    /** @param list<float> $scores */
    public function aggregate(array $scores, string $method, ?float $headJudgeOverride = null): ?float;
}
