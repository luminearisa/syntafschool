<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Infrastructure\Providers;

use App\Modules\Activity\Domain\Contracts\StudentProviderInterface as ActivityStudentProviderInterface;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\StudentProviderInterface as CompetitionStudentProviderInterface;

/** Adapter to the ERP's existing Student provider; no student data is copied. */
class ActivityStudentProvider implements CompetitionStudentProviderInterface
{
    public function __construct(private readonly ActivityStudentProviderInterface $students) {}

    public function snapshot(int $studentId): ?array
    {
        $row = $this->students->snapshot($studentId);

        if ($row === null) {
            return null;
        }

        return [
            ...$row,
            'id' => (int) ($row['id'] ?? $studentId),
            'name' => $row['name'] ?? $row['full_name'] ?? null,
            'active' => (bool) ($row['active'] ?? $row['is_active'] ?? true),
            'grade_level' => $row['grade_level'] ?? $row['class_level'] ?? null,
            'class' => $row['class_name'] ?? null,
        ];
    }

    public function search(string $term, int $limit = 20): array
    {
        return $this->students->search($term, min(100, max(1, $limit)));
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }
}
