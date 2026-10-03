<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Infrastructure\Providers;

use App\Modules\SchoolOperations\Competitions\Domain\Contracts\ScoringProviderInterface;
use Illuminate\Validation\ValidationException;

/** Default extensible numeric/weighted and multi-judge scoring strategies. */
class DefaultScoringProvider implements ScoringProviderInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function weightedCriterion(float $raw, float $weight, float $minimum, float $maximum): float
    {
        if ($maximum <= $minimum) {
            throw ValidationException::withMessages(['rubric' => 'Criterion maximum must be greater than minimum.']);
        }
        $normalized = (($raw - $minimum) / ($maximum - $minimum)) * 100;

        return round($normalized * $weight / 100, 6);
    }

    public function aggregate(array $scores, string $method, ?float $headJudgeOverride = null): ?float
    {
        if ($scores === []) {
            return null;
        }
        $scores = array_map('floatval', array_values($scores));
        sort($scores);

        return match (strtoupper($method)) {
            'MEDIAN', 'CONSENSUS' => $this->median($scores),
            'DROP_HIGHEST' => count($scores) > 1 ? array_sum(array_slice($scores, 0, -1)) / (count($scores) - 1) : $scores[0],
            'DROP_LOWEST' => count($scores) > 1 ? array_sum(array_slice($scores, 1)) / (count($scores) - 1) : $scores[0],
            'HEAD_JUDGE_OVERRIDE' => $headJudgeOverride ?? array_sum($scores) / count($scores),
            'AVERAGE', 'WEIGHTED_AVERAGE' => array_sum($scores) / count($scores),
            default => throw ValidationException::withMessages(['aggregation_method' => 'Unsupported multi-judge aggregation strategy.']),
        };
    }

    private function median(array $scores): float
    {
        $count = count($scores);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $scores[$middle] : ($scores[$middle - 1] + $scores[$middle]) / 2;
    }
}
