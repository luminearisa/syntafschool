<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Domain\Enums\ActionItemStatus;
use App\Modules\Supervisi\Domain\Enums\ActionPlanStatus;
use App\Modules\Supervisi\Models\SupActionItem;
use App\Modules\Supervisi\Models\SupActionPlan;
use App\Modules\Supervisi\Models\SupActionProgress;
use App\Modules\Supervisi\Models\SupVisit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rencana Tindak Lanjut (RTL).
 *
 * RTL dibuat dari temuan kegiatan yang sudah difinalkan, dijalankan oleh yang
 * disupervisi (unggah bukti + laporan kemajuan), lalu diverifikasi atasan.
 * Papan pemantauan menonjolkan butir yang jatuh tempo dan yang menunggu
 * verifikasi supaya tidak ada rencana yang "hilang" setelah rapat.
 */
class ActionPlanService
{
    public function __construct(
        private readonly SupervisiSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------
    // Pembuatan
    // ------------------------------------------------------------------

    /**
     * Susun RTL otomatis dari temuan kegiatan.
     *
     * @param  array<string,mixed>  $payload  title, period_start, period_end, items[]
     */
    public function createFromVisit(SupVisit $visit, array $payload, User $actor): SupActionPlan
    {
        if ($visit->finalized_at === null) {
            throw ValidationException::withMessages([
                'visit_id' => 'RTL disusun setelah hasil kegiatan difinalkan.',
            ]);
        }

        if (SupActionPlan::query()->where('visit_id', $visit->id)->exists()) {
            throw ValidationException::withMessages([
                'visit_id' => 'RTL untuk kegiatan ini sudah ada. Tambahkan butir pada RTL tersebut bila perlu.',
            ]);
        }

        $items = $payload['items'] ?? $this->itemsFromFindings($visit);

        $plan = DB::transaction(function () use ($visit, $payload, $items, $actor): SupActionPlan {
            $plan = SupActionPlan::query()->create([
                'visit_id' => $visit->id,
                'supervisee_employee_id' => $visit->supervisee_employee_id,
                'title' => $payload['title'] ?? 'RTL ' . $visit->code,
                'note' => $payload['note'] ?? null,
                'period_start' => $payload['period_start'] ?? Carbon::now()->toDateString(),
                'period_end' => $payload['period_end'] ?? null,
                'status' => ActionPlanStatus::DRAFT,
                'created_by' => $actor->id,
            ]);

            $this->writeItems($plan, $items);

            return $plan;
        });

        $this->audit->log('action_plan.created_from_visit', $plan, null, $plan->toArray(), null, $actor, [
            'visit_id' => $visit->id,
            'items' => count($items),
        ]);

        return $this->refreshStatus($plan->refresh());
    }

    /** @param array<string,mixed> $payload */
    public function create(array $payload, User $actor): SupActionPlan
    {
        $plan = DB::transaction(function () use ($payload, $actor): SupActionPlan {
            $plan = SupActionPlan::query()->create([
                'visit_id' => $payload['visit_id'] ?? null,
                'supervisee_employee_id' => (int) $payload['supervisee_employee_id'],
                'title' => $payload['title'],
                'note' => $payload['note'] ?? null,
                'period_start' => $payload['period_start'] ?? Carbon::now()->toDateString(),
                'period_end' => $payload['period_end'] ?? null,
                'status' => ActionPlanStatus::DRAFT,
                'created_by' => $actor->id,
            ]);

            $this->writeItems($plan, $payload['items'] ?? []);

            return $plan;
        });

        $this->audit->log('action_plan.created', $plan, null, $plan->toArray(), null, $actor);

        return $this->refreshStatus($plan->refresh());
    }

    /** @param array<string,mixed> $payload */
    public function update(SupActionPlan $plan, array $payload, User $actor): SupActionPlan
    {
        $before = $this->audit->diff($plan);

        $plan->fill(array_filter([
            'title' => $payload['title'] ?? null,
            'note' => $payload['note'] ?? null,
            'period_start' => $payload['period_start'] ?? null,
            'period_end' => $payload['period_end'] ?? null,
        ], fn ($value) => $value !== null))->save();

        $this->audit->log('action_plan.updated', $plan, $before, $this->audit->diff($plan), null, $actor);

        return $plan->refresh();
    }

    // ------------------------------------------------------------------
    // Butir & kemajuan
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $payload */
    public function addItem(SupActionPlan $plan, array $payload, User $actor): SupActionItem
    {
        $this->assertNotVerified($plan);

        $item = $plan->items()->create([
            'indicator_id' => $payload['indicator_id'] ?? null,
            'statement' => $payload['statement'],
            'support_needed' => $payload['support_needed'] ?? null,
            'due_date' => $payload['due_date'] ?? $this->defaultDueDate(),
            'owner_employee_id' => $payload['owner_employee_id'] ?? $plan->supervisee_employee_id,
            'status' => ActionItemStatus::PENDING,
            'sort_order' => (int) ($payload['sort_order'] ?? ($plan->items()->max('sort_order') + 1)),
        ]);

        $this->audit->log('action_item.created', $item, null, $item->toArray(), null, $actor);

        return $this->refreshStatus($plan->refresh())->items()->whereKey($item->id)->firstOrFail();
    }

    /** @param array<string,mixed> $payload */
    public function updateItem(SupActionItem $item, array $payload, User $actor): SupActionItem
    {
        $plan = $item->plan()->firstOrFail();
        $this->assertNotVerified($plan);

        $before = $this->audit->diff($item);

        $item->fill(array_filter([
            'statement' => $payload['statement'] ?? null,
            'support_needed' => $payload['support_needed'] ?? null,
            'due_date' => $payload['due_date'] ?? null,
            'owner_employee_id' => $payload['owner_employee_id'] ?? null,
            'evidence_path' => $payload['evidence_path'] ?? null,
            'result_note' => $payload['result_note'] ?? null,
            'sort_order' => $payload['sort_order'] ?? null,
        ], fn ($value) => $value !== null));

        if (($payload['status'] ?? null) === ActionItemStatus::DROPPED->value) {
            $item->status = ActionItemStatus::DROPPED;
        }

        $item->save();

        $this->audit->log('action_item.updated', $item, $before, $this->audit->diff($item), null, $actor);

        return $this->refreshStatus($plan->refresh())->items()->whereKey($item->id)->firstOrFail();
    }

    /**
     * Catat laporan kemajuan butir RTL.
     *
     * @param  array<string,mixed>  $payload  progress_percent, note, attachment_path
     */
    public function reportProgress(SupActionItem $item, array $payload, User $actor): SupActionItem
    {
        $plan = $item->plan()->firstOrFail();
        $this->assertNotVerified($plan);

        $percent = (int) ($payload['progress_percent'] ?? $item->progress_percent);
        $percent = max(0, min(100, $percent));

        DB::transaction(function () use ($item, $percent, $payload, $actor): void {
            SupActionProgress::query()->create([
                'action_item_id' => $item->id,
                'reported_by' => $this->employeeIdFor($actor),
                'reported_at' => Carbon::now(),
                'progress_percent' => $percent,
                'note' => $payload['note'] ?? null,
                'attachment_path' => $payload['attachment_path'] ?? null,
            ]);

            $item->forceFill([
                'progress_percent' => $percent,
                'status' => match (true) {
                    $percent >= 100 => ActionItemStatus::DONE,
                    $percent > 0 => ActionItemStatus::IN_PROGRESS,
                    default => ActionItemStatus::PENDING,
                },
                'completed_at' => $percent >= 100 ? ($item->completed_at ?? Carbon::now()) : null,
                'evidence_path' => $payload['attachment_path'] ?? $item->evidence_path,
                'result_note' => $payload['note'] ?? $item->result_note,
            ])->save();
        });

        $this->audit->log('action_item.progress', $item, null, [
            'progress_percent' => $percent,
        ], $payload['note'] ?? null, $actor);

        return $this->refreshStatus($plan->refresh())->items()->whereKey($item->id)->firstOrFail();
    }

    /** Verifikasi RTL oleh atasan: hanya setelah seluruh butir selesai. */
    public function verify(SupActionPlan $plan, User $actor, ?string $note = null): SupActionPlan
    {
        $plan->load('items');
        $pending = $plan->items->reject(
            fn (SupActionItem $item) => in_array($item->status, [ActionItemStatus::DONE, ActionItemStatus::DROPPED], true)
        );

        if ($pending->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => $pending->count() . ' butir RTL belum selesai: '
                    . $pending->take(3)->pluck('statement')->implode('; '),
            ]);
        }

        if ($plan->items->where('status', ActionItemStatus::DONE)->isEmpty() && $plan->items->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Tidak ada butir yang dapat diverifikasi (semua butir dihentikan).',
            ]);
        }

        $before = $this->audit->diff($plan);

        $plan->forceFill([
            'status' => ActionPlanStatus::VERIFIED,
            'verified_by' => $this->employeeIdFor($actor),
            'verified_at' => Carbon::now(),
            'note' => $note ?? $plan->note,
        ])->save();

        $this->audit->log('action_plan.verified', $plan, $before, $this->audit->diff($plan), $note, $actor);

        return $plan->refresh();
    }

    public function drop(SupActionPlan $plan, User $actor, string $reason): SupActionPlan
    {
        if ($plan->status === ActionPlanStatus::VERIFIED) {
            throw ValidationException::withMessages(['status' => 'RTL yang sudah diverifikasi tidak dapat dihentikan.']);
        }

        $before = $this->audit->diff($plan);
        $plan->forceFill(['status' => ActionPlanStatus::DROPPED, 'note' => $reason])->save();
        $this->audit->log('action_plan.dropped', $plan, $before, $this->audit->diff($plan), $reason, $actor);

        return $plan->refresh();
    }

    // ------------------------------------------------------------------
    // Papan pemantauan & detail
    // ------------------------------------------------------------------

    /**
     * Papan pemantauan RTL: menonjolkan keterlambatan dan verifikasi.
     *
     * @return array{buckets:array<string,int>,rows:list<array<string,mixed>>}
     */
    public function board(array $filters, User $actor): array
    {
        $plans = $this->visiblePlans($actor)
            ->with(['items', 'supervisee:id,full_name,position', 'visit:id,code'])
            ->when(! empty($filters['supervisee_employee_id']), fn ($query) => $query->where('supervisee_employee_id', (int) $filters['supervisee_employee_id']))
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->limit((int) ($filters['limit'] ?? 200))
            ->get();

        $today = Carbon::today();
        $dueSoonUntil = (clone $today)->addDays((int) ($filters['due_in_days'] ?? 7));

        $rows = [];
        $buckets = ['overdue' => 0, 'due_soon' => 0, 'ongoing' => 0, 'waiting_verification' => 0, 'verified' => 0, 'draft' => 0];

        foreach ($plans as $plan) {
            $status = $this->refreshStatus($plan)->status;
            $plan->load('items');

            $overdue = $plan->items->filter(
                fn (SupActionItem $item) => $item->due_date !== null
                    && $item->due_date->lt($today)
                    && ! in_array($item->status, [ActionItemStatus::DONE, ActionItemStatus::DROPPED], true)
            );

            $dueSoon = $plan->items->filter(
                fn (SupActionItem $item) => $item->due_date !== null
                    && $item->due_date->betweenIncluded($today, $dueSoonUntil)
                    && ! in_array($item->status, [ActionItemStatus::DONE, ActionItemStatus::DROPPED], true)
            );

            $waiting = $status === ActionPlanStatus::DONE
                && $plan->items->contains(fn (SupActionItem $item) => $item->status === ActionItemStatus::DONE);

            if ($overdue->isNotEmpty()) {
                $buckets['overdue']++;
            } elseif ($dueSoon->isNotEmpty()) {
                $buckets['due_soon']++;
            }

            match ($status) {
                ActionPlanStatus::VERIFIED => $buckets['verified']++,
                ActionPlanStatus::DRAFT => $buckets['draft']++,
                default => $buckets[$waiting ? 'waiting_verification' : 'ongoing']++,
            };

            if (($filters['overdue_only'] ?? false) && $overdue->isEmpty()) {
                continue;
            }

            if (($filters['waiting_verification'] ?? false) && ! $waiting) {
                continue;
            }

            $rows[] = $this->presentPlan($plan, $overdue->pluck('id')->all(), $dueSoon->pluck('id')->all());
        }

        return ['buckets' => $buckets, 'rows' => $rows];
    }

    /** @return array<string,mixed> */
    public function detail(SupActionPlan $plan, User $actor): array
    {
        $plan->load(['items.owner:id,full_name', 'items.progress', 'supervisee:id,full_name,position', 'visit:id,code,activity_kind,status,final_score,finalized_at']);

        $today = Carbon::today();
        $overdue = $plan->items->filter(
            fn (SupActionItem $item) => $item->due_date !== null
                && $item->due_date->lt($today)
                && ! in_array($item->status, [ActionItemStatus::DONE, ActionItemStatus::DROPPED], true)
        )->pluck('id')->all();

        return [
            'plan' => $this->presentPlan($plan, $overdue, []),
            'abilities' => $this->abilities($plan, $actor),
        ];
    }

    /** @return array<string,bool> */
    protected function abilities(SupActionPlan $plan, User $actor): array
    {
        $employeeId = $this->employeeIdFor($actor);
        $isOwner = $employeeId !== null && (int) $plan->supervisee_employee_id === $employeeId;
        $verified = $plan->status === ActionPlanStatus::VERIFIED;

        return [
            'view_all' => $this->canSeeAll($actor),
            'report' => ! $verified && ($isOwner || $this->canSeeAll($actor) || $actor->can('supervisi.action_plan.update')),
            'manage' => ! $verified && ($actor->can('supervisi.action_plan.create') || $this->canSeeAll($actor)),
            'verify' => ! $verified && $actor->can('supervisi.action_plan.verify'),
        ];
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /** @param list<array<string,mixed>> $items */
    protected function writeItems(SupActionPlan $plan, array $items): void
    {
        $order = 0;

        foreach ($items as $item) {
            $plan->items()->create([
                'indicator_id' => $item['indicator_id'] ?? null,
                'statement' => (string) $item['statement'],
                'support_needed' => $item['support_needed'] ?? null,
                'due_date' => $item['due_date'] ?? $this->defaultDueDate(),
                'owner_employee_id' => $item['owner_employee_id'] ?? $plan->supervisee_employee_id,
                'status' => ActionItemStatus::PENDING,
                'sort_order' => (int) ($item['sort_order'] ?? $order),
            ]);

            $order++;
        }
    }

    /**
     * Ubah temuan menjadi butir RTL yang dapat dikerjakan.
     *
     * @return list<array<string,mixed>>
     */
    protected function itemsFromFindings(SupVisit $visit): array
    {
        $visit->loadMissing('findings');

        $items = $visit->findings
            ->filter(fn ($finding) => $finding->recommendation !== null && $finding->recommendation !== '')
            ->map(fn ($finding) => [
                'indicator_id' => $finding->indicator_id,
                'statement' => $finding->recommendation,
                'support_needed' => $finding->cause,
                'due_date' => $this->defaultDueDate($finding->priority),
            ])
            ->values()
            ->all();

        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'Kegiatan ini belum memiliki temuan berrekomendasi. Isi temuan lebih dulu atau susun butir RTL secara manual.',
            ]);
        }

        return $items;
    }

    /** Tenggat bawaan: prioritas mendesak/tinggi lebih cepat. */
    protected function defaultDueDate(?string $priority = null): string
    {
        $days = (int) ($this->settings->get('action_plan_default_due_days') ?: 30);

        $days = match ($priority) {
            'URGENT' => max(3, (int) round($days / 4)),
            'HIGH' => max(7, (int) round($days / 2)),
            default => $days,
        };

        return Carbon::now()->addDays($days)->toDateString();
    }

    /** Hitung ulang status RTL dari kondisi butirnya. */
    protected function refreshStatus(SupActionPlan $plan): SupActionPlan
    {
        if (in_array($plan->status, [ActionPlanStatus::VERIFIED, ActionPlanStatus::DROPPED], true)) {
            return $plan;
        }

        $plan->load('items');
        $today = Carbon::today();

        $active = $plan->items->reject(fn (SupActionItem $item) => $item->status === ActionItemStatus::DROPPED);
        $done = $active->filter(fn (SupActionItem $item) => $item->status === ActionItemStatus::DONE);
        $overdue = $active->contains(
            fn (SupActionItem $item) => $item->due_date !== null && $item->due_date->lt($today)
        );

        $status = match (true) {
            $active->isEmpty() => ActionPlanStatus::DRAFT,
            $done->count() === $active->count() => ActionPlanStatus::DONE,
            $overdue => ActionPlanStatus::OVERDUE,
            $active->contains(fn (SupActionItem $item) => $item->progress_percent > 0) => ActionPlanStatus::ONGOING,
            default => ActionPlanStatus::DRAFT,
        };

        if ($plan->status !== $status) {
            $plan->forceFill(['status' => $status])->save();
        }

        return $plan;
    }

    /** @return Collection<int,SupActionPlan> */
    protected function visiblePlans(User $actor)
    {
        $query = SupActionPlan::query()->orderByDesc('id');

        if ($this->canSeeAll($actor)) {
            return $query;
        }

        $employeeId = $this->employeeIdFor($actor);

        return $query->where(function ($builder) use ($employeeId, $actor): void {
            $builder->where('created_by', $actor->id);

            if ($employeeId !== null) {
                $builder->orWhere('supervisee_employee_id', $employeeId)
                    ->orWhereHas('visit', fn ($visit) => $visit->where('supervisor_employee_id', $employeeId));
            }
        });
    }

    /** Tolak akses RTL milik orang lain (selain pihak yang terlibat). */
    public function ensureVisible(SupActionPlan $plan, User $actor): void
    {
        if ($this->canSeeAll($actor)) {
            return;
        }

        $employeeId = $this->employeeIdFor($actor);

        $visible = (int) $plan->created_by === (int) $actor->id
            || ($employeeId !== null && (int) $plan->supervisee_employee_id === $employeeId)
            || ($employeeId !== null && $plan->visit()->where('supervisor_employee_id', $employeeId)->exists());

        abort_unless($visible, 403, 'Anda tidak terlibat pada RTL ini.');
    }

    protected function canSeeAll(User $actor): bool
    {
        return $actor->can('supervisi.visit.view.all')
            || $actor->can('supervisi.manage')
            || $actor->can('supervisi.audit.view');
    }

    protected function assertNotVerified(SupActionPlan $plan): void
    {
        if ($plan->status === ActionPlanStatus::VERIFIED) {
            throw ValidationException::withMessages([
                'status' => 'RTL yang sudah diverifikasi terkunci. Buat RTL baru bila ada tindak lanjut tambahan.',
            ]);
        }
    }

    protected function employeeIdFor(User $actor): ?int
    {
        $id = $actor->userable_id ?? null;

        return $actor->userable_type === \App\Models\Employee::class && $id !== null ? (int) $id : null;
    }

    /**
     * @param  list<int>  $overdueItemIds
     * @param  list<int>  $dueSoonItemIds
     * @return array<string,mixed>
     */
    protected function presentPlan(SupActionPlan $plan, array $overdueItemIds = [], array $dueSoonItemIds = []): array
    {
        $plan->loadMissing(['items.owner:id,full_name', 'supervisee:id,full_name,position', 'visit:id,code']);

        return [
            'id' => $plan->id,
            'title' => $plan->title,
            'note' => $plan->note,
            'status' => $plan->status->value,
            'period_start' => $plan->period_start?->toDateString(),
            'period_end' => $plan->period_end?->toDateString(),
            'verified_at' => $plan->verified_at?->toIso8601String(),
            'verified_by' => $plan->verified_by,
            'supervisee' => $plan->supervisee === null ? null : [
                'id' => $plan->supervisee->id,
                'full_name' => $plan->supervisee->full_name,
                'position' => $plan->supervisee->position,
            ],
            'visit' => $plan->visit === null ? null : [
                'id' => $plan->visit->id,
                'code' => $plan->visit->code,
            ],
            'progress_percent' => $plan->items->isEmpty()
                ? 0
                : (int) round($plan->items->avg('progress_percent')),
            'items' => $plan->items->map(fn (SupActionItem $item) => [
                'id' => $item->id,
                'indicator_id' => $item->indicator_id,
                'statement' => $item->statement,
                'support_needed' => $item->support_needed,
                'due_date' => $item->due_date?->toDateString(),
                'owner' => $item->owner === null ? null : ['id' => $item->owner->id, 'full_name' => $item->owner->full_name],
                'status' => $item->status->value,
                'progress_percent' => (int) $item->progress_percent,
                'evidence_path' => $item->evidence_path,
                'result_note' => $item->result_note,
                'completed_at' => $item->completed_at?->toIso8601String(),
                'is_overdue' => in_array($item->id, $overdueItemIds, true),
                'is_due_soon' => in_array($item->id, $dueSoonItemIds, true),
                'progress' => $item->relationLoaded('progress')
                    ? $item->progress->map(fn (SupActionProgress $row) => [
                        'id' => $row->id,
                        'reported_at' => $row->reported_at?->toIso8601String(),
                        'progress_percent' => (int) $row->progress_percent,
                        'note' => $row->note,
                        'attachment_path' => $row->attachment_path,
                    ])->values()->all()
                    : [],
            ])->values()->all(),
        ];
    }
}
