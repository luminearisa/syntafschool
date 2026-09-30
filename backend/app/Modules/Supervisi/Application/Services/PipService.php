<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Domain\Enums\AppraisalType;
use App\Modules\Supervisi\Models\SupAppraisal;
use App\Modules\Supervisi\Models\SupPipItem;
use App\Modules\Supervisi\Models\SupPipPlan;
use App\Modules\Supervisi\Models\SupPipReview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rencana Perbaikan Kinerja (PIP / Performance Improvement Plan).
 *
 * Dipakai ketika hasil penilaian kinerja berada di predikat terendah:
 * sasaran perbaikan disepakati, ditinjau berkala, lalu diputuskan hasilnya
 * (membaik / sebagian / tidak membaik / diperpanjang).
 */
class PipService
{
    public const OUTCOMES = ['IMPROVED', 'PARTIAL', 'NOT_IMPROVED', 'EXTENDED'];

    public function __construct(
        private readonly SupervisiSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $payload */
    public function create(array $payload, User $actor): SupPipPlan
    {
        $appraisal = isset($payload['appraisal_id'])
            ? SupAppraisal::query()->findOrFail((int) $payload['appraisal_id'])
            : null;

        if ($appraisal !== null && $appraisal->final_score === null) {
            throw ValidationException::withMessages([
                'appraisal_id' => 'Hitung hasil penilaian kinerja sebelum menyusun PIP.',
            ]);
        }

        $plan = DB::transaction(function () use ($payload, $appraisal, $actor): SupPipPlan {
            $plan = SupPipPlan::query()->create([
                'appraisal_id' => $appraisal?->id,
                'supervisee_employee_id' => (int) ($payload['supervisee_employee_id'] ?? $appraisal?->supervisee_employee_id),
                'reason' => $payload['reason'] ?? $this->reasonFromAppraisal($appraisal),
                'expected_standard' => $payload['expected_standard'] ?? null,
                'consequences_note' => $payload['consequences_note'] ?? null,
                'support_provided' => $payload['support_provided'] ?? null,
                'start_date' => $payload['start_date'] ?? Carbon::now()->toDateString(),
                'final_review_date' => $payload['final_review_date'] ?? null,
                'review_cadence' => $payload['review_cadence'] ?? 'BIWEEKLY',
                'status' => 'DRAFT',
                'created_by' => $actor->id,
            ]);

            foreach ($payload['items'] ?? [] as $item) {
                $plan->items()->create([
                    'statement' => $item['statement'],
                    'measure' => $item['measure'] ?? null,
                    'target_value' => $item['target_value'] ?? null,
                    'target_unit' => $item['target_unit'] ?? null,
                    'due_date' => $item['due_date'] ?? null,
                    'status' => 'PENDING',
                ]);
            }

            return $plan;
        });

        $this->audit->log('pip.created', $plan, null, $plan->toArray(), null, $actor, [
            'appraisal_id' => $appraisal?->id,
        ]);

        return $plan->refresh();
    }

    /** @param array<string,mixed> $payload */
    public function update(SupPipPlan $plan, array $payload, User $actor): SupPipPlan
    {
        $this->assertOpen($plan);
        $before = $this->audit->diff($plan);

        $plan->fill(array_filter([
            'reason' => $payload['reason'] ?? null,
            'expected_standard' => $payload['expected_standard'] ?? null,
            'consequences_note' => $payload['consequences_note'] ?? null,
            'support_provided' => $payload['support_provided'] ?? null,
            'start_date' => $payload['start_date'] ?? null,
            'final_review_date' => $payload['final_review_date'] ?? null,
            'review_cadence' => $payload['review_cadence'] ?? null,
            'status' => $payload['status'] ?? null,
        ], fn ($value) => $value !== null))->save();

        $this->audit->log('pip.updated', $plan, $before, $this->audit->diff($plan), null, $actor);

        return $plan->refresh();
    }

    /** @param array<string,mixed> $payload */
    public function addItem(SupPipPlan $plan, array $payload, User $actor): SupPipItem
    {
        $this->assertOpen($plan);

        $item = $plan->items()->create([
            'statement' => $payload['statement'],
            'measure' => $payload['measure'] ?? null,
            'target_value' => $payload['target_value'] ?? null,
            'target_unit' => $payload['target_unit'] ?? null,
            'due_date' => $payload['due_date'] ?? null,
            'status' => 'PENDING',
        ]);

        $this->audit->log('pip_item.created', $item, null, $item->toArray(), null, $actor);

        return $item;
    }

    /** @param array<string,mixed> $payload */
    public function updateItem(SupPipItem $item, array $payload, User $actor): SupPipItem
    {
        $plan = $item->plan()->firstOrFail();
        $this->assertOpen($plan);

        $before = $this->audit->diff($item);

        $item->fill(array_filter([
            'statement' => $payload['statement'] ?? null,
            'measure' => $payload['measure'] ?? null,
            'target_value' => $payload['target_value'] ?? null,
            'target_unit' => $payload['target_unit'] ?? null,
            'due_date' => $payload['due_date'] ?? null,
            'status' => $payload['status'] ?? null,
            'result_note' => $payload['result_note'] ?? null,
        ], fn ($value) => $value !== null))->save();

        $this->audit->log('pip_item.updated', $item, $before, $this->audit->diff($item), null, $actor);

        return $item->refresh();
    }

    /**
     * Catat tinjauan berkala PIP.
     *
     * @param  array<string,mixed>  $payload  progress_note, next_step, status, reviewed_at
     */
    public function recordReview(SupPipPlan $plan, array $payload, User $actor): SupPipReview
    {
        $this->assertOpen($plan);

        $review = $plan->reviews()->create([
            'reviewed_at' => $payload['reviewed_at'] ?? Carbon::now(),
            'reviewer_employee_id' => $this->employeeIdFor($actor) ?? $plan->supervisee_employee_id,
            'progress_note' => $payload['progress_note'] ?? null,
            'next_step' => $payload['next_step'] ?? null,
            'status' => $payload['status'] ?? 'PENDING',
        ]);

        if ($plan->status === 'DRAFT') {
            $plan->forceFill(['status' => 'ACTIVE'])->save();
        }

        $this->audit->log('pip.reviewed', $plan, null, [
            'review_id' => $review->id,
            'status' => $review->status,
        ], $payload['progress_note'] ?? null, $actor);

        return $review;
    }

    /**
     * Putuskan hasil akhir PIP.
     *
     * @param  array<string,mixed>  $payload  outcome, note, extend_until
     */
    public function conclude(SupPipPlan $plan, array $payload, User $actor): SupPipPlan
    {
        $outcome = strtoupper((string) ($payload['outcome'] ?? ''));

        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw ValidationException::withMessages([
                'outcome' => 'Hasil PIP harus salah satu dari: ' . implode(', ', self::OUTCOMES) . '.',
            ]);
        }

        if ($outcome === 'EXTENDED' && empty($payload['final_review_date'])) {
            throw ValidationException::withMessages([
                'final_review_date' => 'PIP yang diperpanjang wajib menyebut tanggal tinjauan akhir yang baru.',
            ]);
        }

        $plan->load('items');
        $unfinished = $plan->items->whereIn('status', ['PENDING', 'IN_PROGRESS'])->count();

        if ($outcome === 'IMPROVED' && $unfinished > 0) {
            throw ValidationException::withMessages([
                'outcome' => "Masih ada {$unfinished} butir PIP yang belum selesai, tidak dapat dinyatakan membaik.",
            ]);
        }

        $before = $this->audit->diff($plan);

        $plan->forceFill([
            'outcome' => $outcome,
            'status' => $outcome === 'EXTENDED' ? 'ACTIVE' : 'CLOSED',
            'final_review_date' => $payload['final_review_date'] ?? $plan->final_review_date,
            'consequences_note' => $payload['note'] ?? $plan->consequences_note,
        ])->save();

        $this->audit->log('pip.concluded', $plan, $before, $this->audit->diff($plan), $payload['note'] ?? null, $actor);

        return $plan->refresh();
    }

    /** Tanda tangan bahwa pegawai memahami PIP-nya. */
    public function acknowledge(SupPipPlan $plan, User $actor): SupPipPlan
    {
        $employeeId = $this->employeeIdFor($actor);

        if ($employeeId === null || (int) $plan->supervisee_employee_id !== $employeeId) {
            abort(403, 'Hanya pegawai yang bersangkutan yang dapat menandai PIP ini.');
        }

        $plan->forceFill(['acknowledged_at' => Carbon::now()])->save();

        $this->audit->log('pip.acknowledged', $plan, null, ['acknowledged_at' => $plan->acknowledged_at?->toIso8601String()], null, $actor);

        return $plan->refresh();
    }

    /**
     * Daftar PIP sesuai kewenangan pengguna.
     *
     * @return list<array<string,mixed>>
     */
    public function list(array $filters, User $actor): array
    {
        $query = SupPipPlan::query()
            ->with(['items', 'reviews', 'supervisee:id,full_name,position', 'appraisal:id,appraisal_type,final_score'])
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

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('supervisee_employee_id', (int) $filters['employee_id']);
        }

        return $query->limit((int) ($filters['limit'] ?? 100))->get()
            ->map(fn (SupPipPlan $plan) => $this->present($plan, $actor))
            ->all();
    }

    /** @return array<string,mixed> */
    public function detail(SupPipPlan $plan, User $actor): array
    {
        $plan->load(['items', 'reviews', 'supervisee:id,full_name,position', 'appraisal:id,appraisal_type,final_score,grade_label']);

        return $this->present($plan, $actor);
    }

    /** Usulan otomatis: dokumen penilaian yang predikatnya memicu PIP. */
    public function suggestedFromAppraisal(SupAppraisal $appraisal, array $calculatorPredicates = []): bool
    {
        if ($appraisal->appraisal_type === AppraisalType::PIP) {
            return false;
        }

        $predicate = $appraisal->final_predicate;

        if ($predicate === null) {
            return false;
        }

        return (bool) ($calculatorPredicates[$predicate]['triggers_pip'] ?? false);
    }

    // ------------------------------------------------------------------

    protected function reasonFromAppraisal(?SupAppraisal $appraisal): ?string
    {
        if ($appraisal === null) {
            return null;
        }

        return sprintf(
            'Hasil %s periode %s–%s berada pada predikat %s (nilai %.2f).',
            $appraisal->appraisal_type->value,
            $appraisal->period_start?->toDateString() ?? '-',
            $appraisal->period_end?->toDateString() ?? '-',
            $appraisal->grade_label ?? ($appraisal->final_predicate ?? '-'),
            (float) $appraisal->final_score,
        );
    }

    protected function assertOpen(SupPipPlan $plan): void
    {
        if ($plan->status === 'CLOSED') {
            throw ValidationException::withMessages([
                'status' => 'PIP ini sudah ditutup. Buat PIP baru bila perlu pembinaan lanjutan.',
            ]);
        }
    }

    /** Tolak akses PIP milik orang lain. */
    public function ensureVisible(SupPipPlan $plan, User $actor): void
    {
        if ($this->canSeeAll($actor)) {
            return;
        }

        $employeeId = $this->employeeIdFor($actor);

        $visible = (int) $plan->created_by === (int) $actor->id
            || ($employeeId !== null && (int) $plan->supervisee_employee_id === $employeeId);

        abort_unless($visible, 403, 'Anda tidak terlibat pada PIP ini.');
    }

    protected function canSeeAll(User $actor): bool
    {
        return $actor->can('supervisi.pip.manage')
            || $actor->can('supervisi.manage')
            || $actor->can('supervisi.audit.view');
    }

    protected function employeeIdFor(User $actor): ?int
    {
        $id = $actor->userable_id ?? null;

        return $actor->userable_type === \App\Models\Employee::class && $id !== null ? (int) $id : null;
    }

    /** @return array<string,mixed> */
    protected function present(SupPipPlan $plan, User $actor): array
    {
        $employeeId = $this->employeeIdFor($actor);

        return [
            'id' => $plan->id,
            'status' => $plan->status,
            'outcome' => $plan->outcome,
            'reason' => $plan->reason,
            'expected_standard' => $plan->expected_standard,
            'consequences_note' => $plan->consequences_note,
            'support_provided' => $plan->support_provided,
            'start_date' => $plan->start_date?->toDateString(),
            'final_review_date' => $plan->final_review_date?->toDateString(),
            'review_cadence' => $plan->review_cadence,
            'acknowledged_at' => $plan->acknowledged_at?->toIso8601String(),
            'supervisee' => $plan->supervisee === null ? null : [
                'id' => $plan->supervisee->id,
                'full_name' => $plan->supervisee->full_name,
                'position' => $plan->supervisee->position,
            ],
            'appraisal' => $plan->appraisal === null ? null : [
                'id' => $plan->appraisal->id,
                'appraisal_type' => $plan->appraisal->appraisal_type->value,
                'final_score' => $plan->appraisal->final_score,
                'grade_label' => $plan->appraisal->grade_label,
            ],
            'items' => $plan->items->map(fn (SupPipItem $item) => [
                'id' => $item->id,
                'statement' => $item->statement,
                'measure' => $item->measure,
                'target_value' => $item->target_value,
                'target_unit' => $item->target_unit,
                'due_date' => $item->due_date?->toDateString(),
                'status' => $item->status,
                'result_note' => $item->result_note,
            ])->values()->all(),
            'reviews' => $plan->reviews->map(fn (SupPipReview $row) => [
                'id' => $row->id,
                'reviewed_at' => $row->reviewed_at?->toIso8601String(),
                'progress_note' => $row->progress_note,
                'next_step' => $row->next_step,
                'status' => $row->status,
            ])->values()->all(),
            'abilities' => [
                'manage' => $this->canSeeAll($actor),
                'acknowledge' => $employeeId !== null
                    && (int) $plan->supervisee_employee_id === $employeeId
                    && $plan->acknowledged_at === null,
            ],
        ];
    }
}
