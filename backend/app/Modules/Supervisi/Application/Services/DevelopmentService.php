<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Models\SupCoachingSession;
use App\Modules\Supervisi\Models\SupDevelopment;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Pengembangan kompetensi & coaching.
 *
 * `sup_developments` mencatat diklat/workshop/mandiri beserta jam dan
 * sertifikatnya (bahan penghitungan angka kredit PKB), sedangkan
 * `sup_coaching_sessions` mencatat pendampingan pribadi beserta komitmen dan
 * tindak lanjutnya.
 */
class DevelopmentService
{
    public const KINDS = ['DIKLAT', 'WORKSHOP', 'COACHING', 'MENTORING', 'LESSON_STUDY', 'MANDIRI', 'PEER_VISIT'];

    public function __construct(
        private readonly SupervisiSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $payload */
    public function record(array $payload, User $actor): SupDevelopment
    {
        $this->assertActsFor($actor, (int) $payload['supervisee_employee_id']);

        $kind = strtoupper((string) ($payload['kind'] ?? 'DIKLAT'));

        if (! in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages([
                'kind' => 'Jenis pengembangan harus salah satu dari: ' . implode(', ', self::KINDS) . '.',
            ]);
        }

        $development = SupDevelopment::query()->create([
            'supervisee_employee_id' => (int) $payload['supervisee_employee_id'],
            'kind' => $kind,
            'title' => $payload['title'],
            'provider' => $payload['provider'] ?? null,
            'hours' => $payload['hours'] ?? null,
            'started_at' => $payload['started_at'] ?? null,
            'finished_at' => $payload['finished_at'] ?? null,
            'cost' => $payload['cost'] ?? null,
            'certificate_no' => $payload['certificate_no'] ?? null,
            'certificate_path' => $payload['certificate_path'] ?? null,
            'status' => $payload['status'] ?? 'PLANNED',
            'linked_visit_id' => $payload['linked_visit_id'] ?? null,
            'linked_action_item_id' => $payload['linked_action_item_id'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->audit->log('development.recorded', $development, null, $development->toArray(), null, $actor);

        return $development;
    }

    /** @param array<string,mixed> $payload */
    public function update(SupDevelopment $development, array $payload, User $actor): SupDevelopment
    {
        $this->assertActsFor($actor, (int) $development->supervisee_employee_id);

        $before = $this->audit->diff($development);

        $development->fill(array_filter([
            'kind' => isset($payload['kind']) ? strtoupper((string) $payload['kind']) : null,
            'title' => $payload['title'] ?? null,
            'provider' => $payload['provider'] ?? null,
            'hours' => $payload['hours'] ?? null,
            'started_at' => $payload['started_at'] ?? null,
            'finished_at' => $payload['finished_at'] ?? null,
            'cost' => $payload['cost'] ?? null,
            'certificate_no' => $payload['certificate_no'] ?? null,
            'certificate_path' => $payload['certificate_path'] ?? null,
            'status' => $payload['status'] ?? null,
        ], fn ($value) => $value !== null))->save();

        $this->audit->log('development.updated', $development, $before, $this->audit->diff($development), null, $actor);

        return $development->refresh();
    }

    public function destroy(SupDevelopment $development, User $actor): void
    {
        $this->assertActsFor($actor, (int) $development->supervisee_employee_id);

        $before = $this->audit->diff($development);
        $development->delete();
        $this->audit->log('development.deleted', $development, $before, null, null, $actor);
    }

    /**
     * Daftar pengembangan + ringkasan jam per jenis (bahan PKB/angka kredit).
     *
     * @return array{rows:list<array<string,mixed>>,summary:array<string,mixed>}
     */
    public function list(array $filters, User $actor): array
    {
        $query = SupDevelopment::query()
            ->with('supervisee:id,full_name,position')
            ->orderByDesc('started_at')
            ->orderByDesc('id');

        if (! $this->canSeeAll($actor)) {
            $employeeId = $this->employeeIdFor($actor);

            $query->where(function ($builder) use ($employeeId, $actor): void {
                $builder->where('created_by', $actor->id);

                if ($employeeId !== null) {
                    $builder->orWhere('supervisee_employee_id', $employeeId);
                }
            });
        }

        if (! empty($filters['employee_id'])) {
            $query->where('supervisee_employee_id', (int) $filters['employee_id']);
        }

        if (! empty($filters['kind'])) {
            $query->where('kind', strtoupper((string) $filters['kind']));
        }

        if (! empty($filters['year'])) {
            $query->whereYear('started_at', (int) $filters['year']);
        }

        $rows = $query->limit((int) ($filters['limit'] ?? 100))->get();

        $byKind = [];
        foreach ($rows as $row) {
            $byKind[$row->kind] = round(($byKind[$row->kind] ?? 0) + (float) $row->hours, 2);
        }

        return [
            'rows' => $rows->map(fn (SupDevelopment $row) => $this->present($row))->all(),
            'summary' => [
                'total_hours' => round((float) $rows->sum('hours'), 2),
                'hours_by_kind' => $byKind,
                'certified' => $rows->filter(fn (SupDevelopment $row) => $row->certificate_no !== null)->count(),
                'completed' => $rows->where('status', 'DONE')->count(),
                'planned' => $rows->where('status', 'PLANNED')->count(),
                'total_cost' => round((float) $rows->sum('cost'), 2),
            ],
        ];
    }

    /** @return array<string,mixed> */
    protected function present(SupDevelopment $row): array
    {
        return [
            'id' => $row->id,
            'kind' => $row->kind,
            'title' => $row->title,
            'provider' => $row->provider,
            'hours' => $row->hours === null ? null : (float) $row->hours,
            'started_at' => $row->started_at?->toDateString(),
            'finished_at' => $row->finished_at?->toDateString(),
            'cost' => $row->cost === null ? null : (float) $row->cost,
            'certificate_no' => $row->certificate_no,
            'certificate_path' => $row->certificate_path,
            'status' => $row->status,
            'linked_visit_id' => $row->linked_visit_id,
            'linked_action_item_id' => $row->linked_action_item_id,
            'supervisee' => $row->supervisee === null ? null : [
                'id' => $row->supervisee->id,
                'full_name' => $row->supervisee->full_name,
                'position' => $row->supervisee->position,
            ],
        ];
    }

    /**
     * Tata kelola saja yang boleh melihat catatan seluruh pegawai.
     *
     * `supervisi.development.manage` dimiliki guru (untuk mencatat
     * pengembangan dirinya sendiri), jadi tidak dipakai sebagai penanda
     * "boleh melihat semua".
     */
    protected function canSeeAll(User $actor): bool
    {
        return $actor->can('supervisi.manage')
            || $actor->can('supervisi.audit.view')
            || $actor->can('supervisi.visit.view.all');
    }

    /** Tolak tindakan atas catatan pegawai lain. */
    protected function assertActsFor(User $actor, int $superviseeEmployeeId): void
    {
        if ($this->canSeeAll($actor)) {
            return;
        }

        $employeeId = $this->employeeIdFor($actor);

        abort_unless(
            $employeeId !== null && $employeeId === $superviseeEmployeeId,
            403,
            'Anda hanya dapat mengelola catatan pengembangan Anda sendiri.',
        );
    }

    protected function employeeIdFor(User $actor): ?int
    {
        $id = $actor->userable_id ?? null;

        return $actor->userable_type === \App\Models\Employee::class && $id !== null ? (int) $id : null;
    }
}
