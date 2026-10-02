<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Domain\Contracts\PositionProviderInterface;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployeeAssignment;
use App\Modules\Hrd\Models\HrJob;
use App\Modules\Hrd\Models\HrJobFamily;
use App\Modules\Hrd\Models\HrJobGrade;
use App\Modules\Hrd\Models\HrJobLevel;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Models\HrPositionHistory;
use Illuminate\Support\Facades\DB;

/**
 * Katalog pekerjaan & formasi (§9–§11, §49, §50).
 *
 * Dua hal yang dijaga di sini:
 *
 *  1. **`filled_headcount` dihitung, bukan diketik.** Setiap penugasan dibuat atau
 *     ditutup, posisi dihitung ulang dari penugasan aktif. Karena itu laporan
 *     lowongan tidak pernah berbeda dari daftar pegawai.
 *  2. **Posisi tidak melapor kepada dirinya sendiri.** Rantai pelaporan posisi
 *     diperiksa terhadap lingkaran; kesalahan ini paling sering terjadi ketika
 *     struktur disusun ulang.
 */
class PositionService implements PositionProviderInterface
{
    public function __construct(
        private readonly ReferenceService $references,
        private readonly HrdAuditService $audit,
    ) {}

    /* ── Posisi ─────────────────────────────────────────────────────────── */

    public function create(array $attributes, User $actor): HrPosition
    {
        $this->validate($attributes);

        if (HrPosition::query()->where('code', $attributes['code'])->exists()) {
            throw HrdValidationException::forField('code', "Kode posisi \"{$attributes['code']}\" sudah dipakai.", 'hr.position.code_taken');
        }

        return DB::transaction(function () use ($attributes, $actor): HrPosition {
            $position = HrPosition::query()->create([
                ...$attributes,
                'authorized_headcount' => (int) ($attributes['authorized_headcount'] ?? 1),
                'planned_headcount' => (int) ($attributes['planned_headcount'] ?? $attributes['authorized_headcount'] ?? 1),
                'status' => $attributes['status'] ?? HrPosition::STATUS_ACTIVE,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->recalculate($position);

            $this->recordHistory($position, 'created', null, $position->toArray(), $attributes['reason'] ?? null, $actor);
            $this->audit->log($actor, 'position.created', $position, null, $position->toArray());

            return $position->refresh();
        });
    }

    public function update(HrPosition $position, array $attributes, User $actor, ?string $reason = null): HrPosition
    {
        return DB::transaction(function () use ($position, $attributes, $actor, $reason): HrPosition {
            $position = HrPosition::query()->whereKey($position->getKey())->lockForUpdate()->firstOrFail();
            $before = $position->toArray();

            $this->validate([...$position->toArray(), ...$attributes], $position);

            if (array_key_exists('authorized_headcount', $attributes)
                && (int) $attributes['authorized_headcount'] < (int) $position->filled_headcount) {
                throw HrdValidationException::forField(
                    'authorized_headcount',
                    "Formasi tidak dapat diturunkan di bawah jumlah terisi ({$position->filled_headcount} orang). Pindahkan penugasan lebih dulu.",
                    'hr.position.headcount_below_filled',
                );
            }

            if (array_key_exists('reports_to_position_id', $attributes)) {
                $this->assertNoReportingCycle($position, $attributes['reports_to_position_id']);
            }

            $position->fill($attributes);
            $position->updated_by = $actor->getKey();
            $position->version = (int) $position->version + 1;
            $position->save();

            $this->recalculate($position);

            $this->recordHistory($position, 'updated', $before, $position->toArray(), $reason, $actor);
            $this->audit->log($actor, 'position.updated', $position, $before, $position->toArray(), $reason);

            return $position->refresh();
        });
    }

    /** Bekukan posisi: tidak boleh diisi, tetapi tidak dihapus (§50). */
    public function freeze(HrPosition $position, User $actor, ?string $reason = null): HrPosition
    {
        return DB::transaction(function () use ($position, $actor, $reason): HrPosition {
            $before = $position->toArray();

            $position->forceFill([
                'status' => HrPosition::STATUS_FROZEN,
                'vacancy_status' => HrPosition::FROZEN,
                'updated_by' => $actor->getKey(),
                'version' => (int) $position->version + 1,
            ])->save();

            $this->recordHistory($position, 'frozen', $before, $position->toArray(), $reason, $actor);
            $this->audit->log($actor, 'position.frozen', $position, $before, $position->toArray(), $reason);

            return $position->refresh();
        });
    }

    /** Mansuhkan posisi. Ditolak bila masih ada penugasan aktif — data tidak dihapus. */
    public function abolish(HrPosition $position, User $actor, ?string $reason = null): HrPosition
    {
        if ($position->activeAssignments()->exists()) {
            throw HrdValidationException::forField(
                'status',
                'Posisi masih dipegang pegawai. Tutup penugasannya lebih dulu sebelum posisi dimansuhkan.',
                'hr.position.has_holders',
            );
        }

        return DB::transaction(function () use ($position, $actor, $reason): HrPosition {
            $before = $position->toArray();

            $position->forceFill([
                'status' => HrPosition::STATUS_ABOLISHED,
                'vacancy_status' => HrPosition::CLOSED,
                'effective_to' => $position->effective_to ?? now()->toDateString(),
                'updated_by' => $actor->getKey(),
                'version' => (int) $position->version + 1,
            ])->save();

            $this->recordHistory($position, 'abolished', $before, $position->toArray(), $reason, $actor);
            $this->audit->log($actor, 'position.abolished', $position, $before, $position->toArray(), $reason);

            return $position->refresh();
        });
    }

    /**
     * Hitung ulang keterisian posisi dari penugasan aktif (§49).
     *
     * `vacancy_status` mengikuti hasil hitungan, kecuali posisi sedang dibekukan
     * atau dimansuhkan — keputusan manusia tidak ditimpa hitungan.
     */
    public function recalculate(HrPosition $position): HrPosition
    {
        $filled = (int) $position->activeAssignments()->distinct('employee_id')->count('employee_id');

        $vacancy = $position->vacancy_status;

        if (! in_array($position->status, [HrPosition::STATUS_FROZEN, HrPosition::STATUS_ABOLISHED], true)) {
            $vacancy = match (true) {
                $filled === 0 => HrPosition::VACANT,
                $filled >= (int) $position->authorized_headcount => HrPosition::FULLY_FILLED,
                default => HrPosition::PARTIALLY_FILLED,
            };
        }

        $position->forceFill(['filled_headcount' => $filled, 'vacancy_status' => $vacancy])->save();

        return $position;
    }

    /** Hitung ulang banyak posisi sekaligus (dipakai setelah impor atau penugasan massal). */
    public function recalculateMany(array $positionIds): int
    {
        $count = 0;

        foreach (array_unique($positionIds) as $positionId) {
            $position = HrPosition::query()->find($positionId);

            if ($position !== null) {
                $this->recalculate($position);
                $count++;
            }
        }

        return $count;
    }

    /* ── Kueri formasi ──────────────────────────────────────────────────── */

    public function findById(int $positionId): ?array
    {
        return HrPosition::query()->with(['job', 'organization', 'department', 'location', 'grade', 'reportsTo'])->find($positionId)?->toArray();
    }

    public function findByCode(string $code): ?array
    {
        return HrPosition::query()->with(['job', 'organization'])->where('code', $code)->first()?->toArray();
    }

    public function holdersOf(int $positionId, ?string $onDate = null): array
    {
        return HrEmployeeAssignment::query()
            ->with(['employee.person:id,full_name,photo_path', 'employee:id,employee_number,person_id'])
            ->where('position_id', $positionId)
            ->whereIn('status', ['active', 'ended'])
            ->when($onDate !== null, fn ($q) => $q->effectiveOn($onDate))
            ->when($onDate === null, fn ($q) => $q->where('status', 'active'))
            ->get()
            ->map(fn (HrEmployeeAssignment $assignment) => [
                'employee_id' => (int) $assignment->employee_id,
                'employee_number' => $assignment->employee?->employee_number,
                'name' => $assignment->employee?->displayName(),
                'assignment_type' => $assignment->assignment_type,
                'is_primary' => (bool) $assignment->is_primary,
                'start_date' => $assignment->start_date?->toDateString(),
                'end_date' => $assignment->end_date?->toDateString(),
                'percentage' => $assignment->percentage,
            ])
            ->all();
    }

    public function vacancies(?int $organizationId = null): array
    {
        return HrPosition::query()
            ->with(['job:id,title,code', 'organization:id,name,code', 'department:id,name'])
            ->withVacancy()
            ->open()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->get()
            ->map(fn (HrPosition $position) => [
                'id' => (int) $position->getKey(),
                'code' => $position->code,
                'title' => $position->title,
                'job_title' => $position->job?->title,
                'organization' => $position->organization?->name,
                'department' => $position->department?->name,
                'authorized_headcount' => (int) $position->authorized_headcount,
                'filled_headcount' => (int) $position->filled_headcount,
                'vacant_count' => $position->vacantCount(),
                'vacancy_status' => $position->vacancy_status,
                'target_fill_date' => $position->target_fill_date?->toDateString(),
            ])
            ->all();
    }

    public function headcountSummary(?int $organizationId = null): array
    {
        $query = HrPosition::query()->open()->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId));

        $rows = $query->selectRaw('coalesce(sum(authorized_headcount),0) as authorized, coalesce(sum(planned_headcount),0) as planned, coalesce(sum(filled_headcount),0) as filled')->first();

        $authorized = (int) ($rows->authorized ?? 0);
        $planned = (int) ($rows->planned ?? 0);
        $filled = (int) ($rows->filled ?? 0);

        return [
            'authorized' => $authorized,
            'planned' => $planned,
            'filled' => $filled,
            'vacant' => max(0, $authorized - $filled),
        ];
    }

    public function reportsTo(int $positionId): array
    {
        return HrPosition::query()->where('reports_to_position_id', $positionId)->get(['id', 'code', 'title'])->toArray();
    }

    /* ── Katalog pekerjaan (§9–§11) ─────────────────────────────────────── */

    public function createJob(array $attributes, User $actor): HrJob
    {
        $this->references->assertValid('employment_type', $attributes['employment_category'] ?? null, 'employment_category');

        $job = HrJob::query()->create([
            ...$attributes,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]);

        $this->audit->log($actor, 'job.created', $job, null, $job->toArray());

        return $job;
    }

    public function updateJob(HrJob $job, array $attributes, User $actor): HrJob
    {
        $before = $job->toArray();
        $job->fill($attributes);
        $job->updated_by = $actor->getKey();
        $job->save();

        $this->audit->log($actor, 'job.updated', $job, $before, $job->toArray());

        return $job->refresh();
    }

    public function createJobFamily(array $attributes, User $actor): HrJobFamily
    {
        $family = HrJobFamily::query()->create($attributes);
        $this->audit->log($actor, 'job_family.created', $family, null, $family->toArray());

        return $family;
    }

    public function createJobLevel(array $attributes, User $actor): HrJobLevel
    {
        $level = HrJobLevel::query()->create($attributes);
        $this->audit->log($actor, 'job_level.created', $level, null, $level->toArray());

        return $level;
    }

    public function createJobGrade(array $attributes, User $actor): HrJobGrade
    {
        $grade = HrJobGrade::query()->create($attributes);
        $this->audit->log($actor, 'job_grade.created', $grade, null, $grade->toArray());

        return $grade;
    }

    /* ── Pemeriksaan ────────────────────────────────────────────────────── */

    /** @param array<string, mixed> $attributes */
    private function validate(array $attributes, ?HrPosition $existing = null): void
    {
        if (empty($attributes['title'])) {
            throw HrdValidationException::forField('title', 'Nama posisi wajib diisi.', 'hr.position.title_required');
        }

        if (empty($attributes['organization_id'])) {
            throw HrdValidationException::forField('organization_id', 'Posisi wajib berada pada satu unit organisasi.', 'hr.position.organization_required');
        }

        $authorized = (int) ($attributes['authorized_headcount'] ?? 1);

        if ($authorized < 1) {
            throw HrdValidationException::forField('authorized_headcount', 'Formasi minimal satu kursi.', 'hr.position.headcount_min');
        }

        $this->references->assertValid('position_type', $attributes['position_type'] ?? null, 'position_type');
        $this->references->assertValid('employment_type', $attributes['employment_type_code'] ?? null, 'employment_type_code');
        $this->references->assertValid('worker_type', $attributes['worker_type_code'] ?? null, 'worker_type_code');

        if (! empty($attributes['effective_from']) && ! empty($attributes['effective_to'])
            && strtotime((string) $attributes['effective_to']) < strtotime((string) $attributes['effective_from'])) {
            throw HrdValidationException::forField('effective_to', 'Tanggal berakhir tidak boleh mendahului tanggal berlaku.', 'hr.position.invalid_period');
        }

        if ($existing === null && empty($attributes['job_id']) && empty($attributes['title'])) {
            throw HrdValidationException::forField('job_id', 'Posisi harus merujuk satu jabatan (job) atau memiliki judul sendiri.', 'hr.position.job_required');
        }
    }

    private function assertNoReportingCycle(HrPosition $position, int|string|null $reportsToId): void
    {
        if ($reportsToId === null || $reportsToId === '') {
            return;
        }

        $visited = [];
        $current = HrPosition::query()->find($reportsToId);

        while ($current !== null) {
            if ((int) $current->getKey() === (int) $position->getKey()) {
                throw HrdValidationException::forField(
                    'reports_to_position_id',
                    'Rantai pelaporan posisi akan membentuk lingkaran.',
                    'hr.position.reporting_cycle',
                );
            }

            if (in_array((int) $current->getKey(), $visited, true)) {
                break;
            }

            $visited[] = (int) $current->getKey();
            $current = $current->reportsTo;
        }
    }

    private function recordHistory(HrPosition $position, string $changeType, ?array $before, ?array $after, ?string $reason, User $actor): void
    {
        HrPositionHistory::query()->create([
            'position_id' => $position->getKey(),
            'change_type' => $changeType,
            'before' => $before,
            'after' => $after,
            'effective_from' => $position->effective_from,
            'reason' => $reason,
            'actor_id' => $actor->getKey(),
        ]);
    }
}
