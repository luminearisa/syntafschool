<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Domain\Enums\ApprovalStatus;
use App\Modules\Workflow\Domain\Enums\InstanceStatus;
use App\Modules\Workflow\Domain\Enums\SlaStatus;
use App\Modules\Workflow\Models\WorkflowApproval;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kotak masuk terpusat (§50, §51).
 *
 * Inilah janji utama engine bagi pengguna: **satu tempat** untuk semua
 * persetujuan dari modul mana pun — pengadaan, cuti, tagihan, dokumen, aset —
 * dengan penyaring yang benar-benar dipakai orang: terlambat, segera jatuh
 * tempo, prioritas, unit, modul, nominal, dan tanggal.
 *
 * Pengurutan bawaan mendahulukan yang paling mendesak (jatuh tempo terdekat),
 * bukan yang terbaru.
 */
class WorkflowInboxService
{
    public function __construct(
        private readonly WorkflowAccessService $access,
    ) {
    }

    /**
     * My Approvals (§50).
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, WorkflowApproval>
     */
    public function myApprovals(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = WorkflowApproval::query()
            ->with([
                'instance.definition:id,code,name,module',
                'instance.requester:id,name',
                'step:id,node_key,name,status,sla_status,due_at,quorum_policy,quorum_value,total_approvers,approvals_count',
                'originalApprover:id,name',
            ])
            ->where('approver_id', $user->id)
            ->whereIn('status', [ApprovalStatus::PENDING->value])
            ->whereHas('instance', fn (Builder $q) => $q->open());

        $this->applyFilters($query, $filters, includeDueFilters: true);

        match ((string) ($filters['bucket'] ?? 'pending')) {
            'overdue' => $query->where(fn (Builder $q) => $q->where('due_at', '<', now())->orWhereHas('step', fn (Builder $s) => $s->where('sla_status', SlaStatus::OVERDUE->value))),
            'due_soon' => $query->whereBetween('due_at', [now(), now()->addHours(24)]),
            'today' => $query->whereDate('due_at', now()->toDateString()),
            default => null,
        };

        $sort = (string) ($filters['sort'] ?? 'due_at');

        if ($sort === 'amount') {
            $query->addSelect([
                'instance_amount' => WorkflowInstance::query()
                    ->select('amount')
                    ->whereColumn('workflow_instances.id', 'workflow_approvals.instance_id')
                    ->limit(1),
            ])->orderByDesc('instance_amount');
        } elseif ($sort === 'created') {
            $query->orderByDesc('assigned_at');
        } else {
            $query->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')->orderBy('due_at');
        }

        return $query->paginate($this->perPage($filters));
    }

    /**
     * My Requests (§51).
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, WorkflowInstance>
     */
    public function myRequests(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = WorkflowInstance::query()
            ->with(['definition:id,code,name,module', 'requester:id,name'])
            ->where('requester_id', $user->id);

        $this->applyInstanceFilters($query, $filters);

        $status = $filters['status'] ?? null;

        if ($status === 'open') {
            $query->open();
        } elseif (is_string($status) && $status !== '' && $status !== 'ALL') {
            $query->where('status', strtoupper($status));
        }

        return $query->orderByDesc('created_at')->paginate($this->perPage($filters));
    }

    /**
     * Semua request yang terlihat pengguna (manager/auditor).
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, WorkflowInstance>
     */
    public function visibleInstances(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = $this->access->scopeInstances(
            WorkflowInstance::query()->with(['definition:id,code,name,module', 'requester:id,name']),
            $user,
        );

        $this->applyInstanceFilters($query, $filters);

        if (($filters['status'] ?? null) === 'open') {
            $query->open();
        } elseif (is_string($filters['status'] ?? null) && $filters['status'] !== '' && $filters['status'] !== 'ALL') {
            $query->where('status', strtoupper((string) $filters['status']));
        }

        if (($filters['bucket'] ?? null) === 'overdue') {
            $query->where('due_at', '<', now())->open();
        }

        return $query->orderByDesc('created_at')->paginate($this->perPage($filters));
    }

    /** Berapa banyak pekerjaan yang menunggu pengguna ini (badge menu). */
    public function counter(User $user): array
    {
        $base = WorkflowApproval::query()
            ->where('approver_id', $user->id)
            ->where('status', ApprovalStatus::PENDING->value)
            ->whereHas('instance', fn (Builder $q) => $q->open());

        return [
            'pending' => (clone $base)->count(),
            'overdue' => (clone $base)->where('due_at', '<', now())->count(),
            'due_soon' => (clone $base)->whereBetween('due_at', [now(), now()->addHours(24)])->count(),
            'my_requests_open' => WorkflowInstance::query()
                ->where('requester_id', $user->id)
                ->open()
                ->count(),
            'my_requests_returned' => WorkflowInstance::query()
                ->where('requester_id', $user->id)
                ->where('status', InstanceStatus::RETURNED->value)
                ->count(),
        ];
    }

    /**
     * @param  Builder<WorkflowApproval>  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters(Builder $query, array $filters, bool $includeDueFilters = false): void
    {
        if (! empty($filters['module'])) {
            $query->whereHas('instance', fn (Builder $q) => $q->where('module', $filters['module']));
        }

        if (! empty($filters['definition'])) {
            $query->whereHas('instance.definition', fn (Builder $q) => $q->where('code', $filters['definition']));
        }

        if (! empty($filters['priority'])) {
            $query->whereHas('instance', fn (Builder $q) => $q->where('priority', strtoupper((string) $filters['priority'])));
        }

        if (! empty($filters['department'])) {
            $query->whereHas('instance', fn (Builder $q) => $q->where('requester_department', $filters['department']));
        }

        if (! empty($filters['amount_min'])) {
            $query->whereHas('instance', fn (Builder $q) => $q->where('amount', '>=', (float) $filters['amount_min']));
        }

        if (! empty($filters['amount_max'])) {
            $query->whereHas('instance', fn (Builder $q) => $q->where('amount', '<=', (float) $filters['amount_max']));
        }

        if (! empty($filters['search'])) {
            $term = '%'.mb_strtolower((string) $filters['search']).'%';

            $query->whereHas('instance', function (Builder $q) use ($term): void {
                $q->whereRaw('LOWER(code) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(title) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(subject_reference) LIKE ?', [$term]);
            });
        }

        if ($includeDueFilters && ! empty($filters['due_before'])) {
            $query->where('due_at', '<=', $filters['due_before']);
        }
    }

    /**
     * @param  Builder<WorkflowInstance>  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyInstanceFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['module'])) {
            $query->where('module', $filters['module']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', strtoupper((string) $filters['priority']));
        }

        if (! empty($filters['department'])) {
            $query->where('requester_department', $filters['department']);
        }

        if (! empty($filters['subject_type'])) {
            $query->where('subject_type', $filters['subject_type']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.mb_strtolower((string) $filters['search']).'%';

            $query->where(function (Builder $q) use ($term): void {
                $q->whereRaw('LOWER(code) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(title) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(subject_reference) LIKE ?', [$term]);
            });
        }
    }

    /** @param array<string, mixed> $filters */
    protected function perPage(array $filters): int
    {
        return min(100, max(5, (int) ($filters['per_page'] ?? 20)));
    }

    /** Daftar nilai filter yang tersedia (untuk dropdown UI). */
    public function filterOptions(User $user): array
    {
        return [
            'modules' => WorkflowInstance::query()
                ->when(! $user->can('workflow.instance.view'), fn (Builder $q) => $q->where('requester_id', $user->id))
                ->distinct()
                ->orderBy('module')
                ->pluck('module')
                ->all(),
            'statuses' => InstanceStatus::values(),
            'priorities' => \App\Modules\Workflow\Domain\Enums\Priority::values(),
            'buckets' => ['pending', 'overdue', 'due_soon', 'today'],
        ];
    }
}
