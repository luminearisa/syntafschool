<?php

namespace App\Services\Boarding;

use App\Models\BoardingPlacement;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Monitoring keberadaan santri (§42) — daftar "siapa di mana, dalam keadaan apa".
 *
 * Sumber kebenaran tunggalnya adalah `BoardingStatusService`; layanan ini hanya
 * merangkai daftar santri (penempatan aktif, difilter per area) dengan kartu
 * status + nama, plus hitung per status untuk legend kartu.
 */
class BoardingMonitoringService
{
    public function __construct(
        protected readonly BoardingScopeService $scope,
        protected readonly BoardingStatusService $statuses,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters  dormitory_id, building_id, room_id, status, search
     * @return array<string, mixed>
     */
    public function overview(array $filters = [], ?User $actor = null, int $perPage = 25): array
    {
        $allowed = $this->scope->studentIdsFor($actor);

        $ids = BoardingPlacement::query()
            ->active()
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('student_id', $allowed ?: [0]))
            ->when(is_numeric($filters['dormitory_id'] ?? null), fn (Builder $q) => $q->forDormitory((int) $filters['dormitory_id']))
            ->when(is_numeric($filters['building_id'] ?? null), fn (Builder $q) => $q->forBuilding((int) $filters['building_id']))
            ->when(is_numeric($filters['room_id'] ?? null), fn (Builder $q) => $q->forRoom((int) $filters['room_id']))
            ->when(
                filled($filters['search'] ?? null),
                fn (Builder $q) => $q->whereHas('student', fn (Builder $s) => $s->where('full_name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('nis', 'like', '%'.$filters['search'].'%')),
            )
            ->pluck('student_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $summaries = $this->statuses->summariesFor($ids);
        $counts = $this->statuses->countsFor($ids);

        $names = Student::query()
            ->whereIn('id', $ids)
            ->pluck('full_name', 'id');

        $nins = Student::query()
            ->whereIn('id', $ids)
            ->pluck('nis', 'id');

        $rows = [];

        foreach ($ids as $id) {
            $summary = $summaries[$id] ?? [];
            $status = $summary['status'] ?? BoardingStatusService::UNKNOWN;

            if (filled($filters['status'] ?? null) && $status !== (string) $filters['status']) {
                continue;
            }

            $rows[] = $summary + [
                'full_name' => $names[$id] ?? null,
                'nis' => $nins[$id] ?? null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp((string) $a['full_name'], (string) $b['full_name']));

        $total = count($rows);
        $perPage = max(1, min($perPage, 200));
        $page = max(1, (int) ($filters['page'] ?? 1));

        return [
            'counts' => $counts,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'status_options' => BoardingStatusService::options(),
        ];
    }

    /**
     * Kartu satu santri untuk halaman detail monitoring.
     *
     * @return array<string, mixed>
     */
    public function studentCard(int $studentId, ?User $actor = null): array
    {
        $this->scope->assertCanAccessStudent($actor, $studentId);

        $student = Student::query()->find($studentId);
        $summary = $this->statuses->summaryFor($studentId);

        return $summary + [
            'full_name' => $student?->full_name,
            'nis' => $student?->nis,
        ];
    }
}
