<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Workflow\Application\Services\DelegationService;
use App\Modules\Workflow\Application\Services\EscalationService;
use App\Modules\Workflow\Application\Services\SlaService;
use App\Modules\Workflow\Application\Services\WorkflowAnalyticsService;
use App\Modules\Workflow\Application\Services\WorkflowAuditService;
use App\Modules\Workflow\Application\Services\WorkflowBuilderService;
use App\Modules\Workflow\Domain\Contracts\BusinessCalendarInterface;
use App\Modules\Workflow\Domain\Contracts\OrganizationalDirectoryInterface;
use App\Modules\Workflow\Domain\Enums\AuditAction;
use App\Modules\Workflow\Domain\Enums\DelegationStatus;
use App\Modules\Workflow\Domain\Enums\OrgScopeType;
use App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar;
use App\Modules\Workflow\Models\WorkflowCalendar;
use App\Modules\Workflow\Models\WorkflowCalendarHoliday;
use App\Modules\Workflow\Models\WorkflowDelegation;
use App\Modules\Workflow\Models\WorkflowEscalation;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowOrgAssignment;
use App\Modules\Workflow\Models\WorkflowOrgReport;
use App\Modules\Workflow\Models\WorkflowSlaRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operasional workflow: delegasi, SLA, eskalasi, kalender, direktori, analitik,
 * audit, dan kesehatan modul (§39–§42, §48, §52, §53, §70).
 */
class WorkflowAdminController extends BaseApiController
{
    public function __construct(
        private readonly DelegationService $delegations,
        private readonly SlaService $sla,
        private readonly EscalationService $escalations,
        private readonly WorkflowAnalyticsService $analytics,
        private readonly WorkflowAuditService $audit,
        private readonly BusinessCalendarInterface $calendar,
        private readonly OrganizationalDirectoryInterface $directory,
        private readonly WorkflowBuilderService $builder,
        private readonly WorkflowRegistrar $registrar,
    ) {
    }

    // ---------------------------------------------------------------- delegasi

    public function delegations(Request $request): JsonResponse
    {
        $query = WorkflowDelegation::query()->with(['delegator:id,name', 'delegate:id,name']);

        if (! $request->user()->can('workflow.delegation.view')) {
            $query->where('delegator_id', $request->user()->id);
        }

        if ($status = $request->string('status')->toString()) {
            $query->where('status', strtoupper($status));
        }

        return $this->successResponse(
            $query->orderByDesc('start_at')->paginate(min(100, (int) $request->integer('per_page', 20)))
                ->through(fn (WorkflowDelegation $delegation) => $this->describeDelegation($delegation)),
        );
    }

    public function storeDelegation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'delegator_id' => ['nullable', 'integer', 'exists:users,id'],
            'delegate_id' => ['required', 'integer', 'exists:users,id'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'reason' => ['nullable', 'string', 'max:300'],
            'scope' => ['nullable', 'array'],
            'notify_delegator' => ['nullable', 'boolean'],
        ]);

        $delegator = isset($data['delegator_id'])
            ? \App\Models\User::query()->findOrFail($data['delegator_id'])
            : $request->user();

        if ($delegator->id !== $request->user()->id && ! $request->user()->can('workflow.delegation.manage')) {
            abort(403, 'Anda hanya dapat membuat delegasi atas nama sendiri.');
        }

        $delegation = $this->delegations->create($delegator, $data, $request->user());

        return $this->createdResponse($this->describeDelegation($delegation->load(['delegator:id,name', 'delegate:id,name'])), 'Delegasi dibuat.');
    }

    public function revokeDelegation(Request $request, WorkflowDelegation $delegation): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        if ($delegation->delegator_id !== $request->user()->id && ! $request->user()->can('workflow.delegation.manage')) {
            abort(403, 'Anda tidak berwenang mencabut delegasi ini.');
        }

        $delegation = $this->delegations->revoke($delegation, $request->user(), $data['reason'] ?? null);

        return $this->successResponse($this->describeDelegation($delegation->load(['delegator:id,name', 'delegate:id,name'])), 'Delegasi dicabut.');
    }

    // ------------------------------------------------------------------- SLA

    public function sla(Request $request): JsonResponse
    {
        $query = WorkflowSlaRecord::query()->with(['instance:id,code,title,module,priority', 'step:id,node_key,name,status']);

        if ($status = $request->string('status')->toString()) {
            $query->where('status', strtoupper($status));
        }

        if ($request->boolean('open_only', true)) {
            $query->whereIn('status', ['ON_TIME', 'DUE_SOON', 'OVERDUE', 'ESCALATED']);
        }

        return $this->successResponse(
            $query->orderBy('due_at')->paginate(min(100, (int) $request->integer('per_page', 20)))
                ->through(fn (WorkflowSlaRecord $record) => [
                    'id' => $record->id,
                    'instance' => $record->instance?->code,
                    'title' => $record->instance?->title,
                    'module' => $record->instance?->module,
                    'step' => $record->step?->name,
                    'node_key' => $record->step?->node_key,
                    'step_status' => $record->step?->status->value,
                    'sla_minutes' => (int) $record->sla_minutes,
                    'sla_human' => $this->sla->humanize((int) $record->sla_minutes),
                    'started_at' => $record->started_at?->toIso8601String(),
                    'due_at' => $record->due_at?->toIso8601String(),
                    'status' => $record->status->value,
                    'escalation_count' => (int) $record->escalation_count,
                    'remaining_minutes' => $record->remainingMinutes(),
                ]),
        );
    }

    /** Jalankan sapuan SLA sekarang (idempoten) — untuk admin tanpa cron. */
    public function sweepSla(Request $request): JsonResponse
    {
        $stats = $this->escalations->sweep((int) $request->integer('limit', 500));

        return $this->successResponse($stats, 'Sapuan SLA dijalankan.');
    }

    public function escalations(Request $request): JsonResponse
    {
        $query = WorkflowEscalation::query()->with(['instance:id,code,title', 'step:id,node_key,name']);

        return $this->successResponse([
            'summary' => $this->escalations->summary((int) $request->integer('days', 30)),
            'rows' => $query->orderByDesc('created_at')->paginate(min(100, (int) $request->integer('per_page', 20)))
                ->through(fn (WorkflowEscalation $escalation) => [
                    'id' => $escalation->id,
                    'instance' => $escalation->instance?->code,
                    'title' => $escalation->instance?->title,
                    'step' => $escalation->step?->name,
                    'level' => (int) $escalation->level,
                    'action' => $escalation->action->value,
                    'status' => $escalation->status,
                    'to_user_id' => $escalation->to_user_id,
                    'reason' => $escalation->reason,
                    'applied_at' => $escalation->applied_at?->toIso8601String(),
                    'created_at' => $escalation->created_at?->toIso8601String(),
                ]),
        ]);
    }

    // -------------------------------------------------------------- kalender

    public function calendars(): JsonResponse
    {
        $calendars = WorkflowCalendar::query()->with('holidays')->orderByDesc('is_default')->get()->map(fn (WorkflowCalendar $calendar) => [
            'id' => $calendar->id,
            'code' => $calendar->code,
            'name' => $calendar->name,
            'timezone' => $calendar->timezone,
            'work_days' => $calendar->workDays(),
            'work_start' => $calendar->workStart(),
            'work_end' => $calendar->workEnd(),
            'is_default' => (bool) $calendar->is_default,
            'is_active' => (bool) $calendar->is_active,
            'holidays' => $calendar->holidays->map(fn (WorkflowCalendarHoliday $holiday) => [
                'id' => $holiday->id,
                'date' => $holiday->date?->toDateString(),
                'name' => $holiday->name,
                'is_working_day' => (bool) $holiday->is_working_day,
            ])->all(),
        ]);

        return $this->successResponse([
            'calendars' => $calendars,
            'config' => [
                'respect_working_hours' => (bool) config('workflow.business_calendar.respect_working_hours'),
                'timezone' => config('workflow.business_calendar.timezone'),
                'weekend' => config('workflow.business_calendar.weekend'),
            ],
        ]);
    }

    public function storeCalendar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:150'],
            'timezone' => ['nullable', 'string', 'max:60'],
            'work_days' => ['nullable', 'array'],
            'work_days.*' => ['integer', 'between:1,7'],
            'work_start' => ['nullable', 'date_format:H:i'],
            'work_end' => ['nullable', 'date_format:H:i'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $calendar = WorkflowCalendar::query()->updateOrCreate(
            ['code' => strtoupper($data['code'])],
            $data + ['is_active' => true],
        );

        if (($data['is_default'] ?? false) === true) {
            WorkflowCalendar::query()->where('id', '!=', $calendar->id)->update(['is_default' => false]);
        }

        $this->audit->record(
            AuditAction::CALENDAR_UPDATED,
            null,
            $calendar,
            after: $data,
            actor: $request->user(),
            extra: ['target_type' => WorkflowCalendar::class, 'target_id' => (string) $calendar->id],
        );

        return $this->createdResponse(['id' => $calendar->id, 'code' => $calendar->code], 'Kalender disimpan.');
    }

    public function storeHoliday(Request $request, WorkflowCalendar $calendar): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:150'],
            'is_working_day' => ['nullable', 'boolean'],
        ]);

        $holiday = WorkflowCalendarHoliday::query()->updateOrCreate(
            ['calendar_id' => $calendar->id, 'date' => $data['date']],
            ['name' => $data['name'], 'is_working_day' => (bool) ($data['is_working_day'] ?? false)],
        );

        return $this->createdResponse(['id' => $holiday->id, 'date' => $holiday->date?->toDateString()], 'Hari kalender disimpan.');
    }

    public function destroyHoliday(WorkflowCalendarHoliday $holiday): JsonResponse
    {
        $holiday->delete();

        return $this->successResponse(null, 'Hari kalender dihapus.');
    }

    // -------------------------------------------------------------- direktori

    public function directory(Request $request): JsonResponse
    {
        $assignments = WorkflowOrgAssignment::query()->with('user:id,name')->orderBy('scope_type')->orderBy('scope_key')->get()->map(fn (WorkflowOrgAssignment $assignment) => [
            'id' => $assignment->id,
            'scope_type' => $assignment->scope_type->value,
            'scope_key' => $assignment->scope_key,
            'user_id' => $assignment->user_id,
            'user' => $assignment->user?->name,
            'is_primary' => (bool) $assignment->is_primary,
            'priority' => (int) $assignment->priority,
            'is_active' => (bool) $assignment->is_active,
            'valid_from' => $assignment->valid_from?->toDateString(),
            'valid_to' => $assignment->valid_to?->toDateString(),
        ]);

        $reports = WorkflowOrgReport::query()->with(['user:id,name', 'manager:id,name', 'backupManager:id,name'])->get()->map(fn (WorkflowOrgReport $report) => [
            'id' => $report->id,
            'user_id' => $report->user_id,
            'user' => $report->user?->name,
            'manager_user_id' => $report->manager_user_id,
            'manager' => $report->manager?->name,
            'backup_user_id' => $report->backup_user_id,
            'backup_manager' => $report->backupManager?->name,
            'effective_from' => $report->effective_from?->toDateString(),
            'effective_to' => $report->effective_to?->toDateString(),
        ]);

        return $this->successResponse([
            'scope_types' => array_map(fn (OrgScopeType $type) => ['value' => $type->value, 'label' => $type->label()], OrgScopeType::cases()),
            'assignments' => $assignments,
            'reports' => $reports,
            'resolved_example' => [
                'requester_department' => $request->user() === null ? null : $this->directory->departmentOf($request->user()->id),
                'requester_manager' => $request->user() === null ? null : $this->directory->managerOf($request->user()->id),
            ],
        ]);
    }

    public function storeAssignment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope_type' => ['required', 'string', 'max:30'],
            'scope_key' => ['required', 'string', 'max:120'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'is_primary' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        $type = OrgScopeType::tryFrom(strtoupper($data['scope_type']));

        if ($type === null) {
            abort(422, 'Jenis cakupan tidak dikenal.');
        }

        $assignment = WorkflowOrgAssignment::query()->updateOrCreate(
            ['scope_type' => $type->value, 'scope_key' => $data['scope_key'], 'user_id' => $data['user_id']],
            $data + ['is_active' => true, 'created_by' => $request->user()->id],
        );

        $this->audit->record(
            AuditAction::DIRECTORY_UPDATED,
            null,
            $assignment,
            after: $data,
            actor: $request->user(),
            extra: ['target_type' => WorkflowOrgAssignment::class, 'target_id' => (string) $assignment->id],
        );

        return $this->createdResponse(['id' => $assignment->id], 'Cakupan organisasi disimpan.');
    }

    public function destroyAssignment(WorkflowOrgAssignment $assignment): JsonResponse
    {
        $assignment->forceFill(['is_active' => false])->save();

        return $this->successResponse(null, 'Cakupan organisasi dinonaktifkan.');
    }

    public function storeReport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'manager_user_id' => ['required', 'integer', 'exists:users,id'],
            'backup_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        $report = WorkflowOrgReport::query()->updateOrCreate(
            ['user_id' => $data['user_id']],
            $data + ['created_by' => $request->user()->id],
        );

        $this->audit->record(
            AuditAction::DIRECTORY_UPDATED,
            null,
            $report,
            after: $data,
            actor: $request->user(),
            extra: ['target_type' => WorkflowOrgReport::class, 'target_id' => (string) $report->id],
        );

        return $this->createdResponse(['id' => $report->id], 'Relasi atasan disimpan.');
    }

    // --------------------------------------------------------------- analitik

    public function dashboard(Request $request): JsonResponse
    {
        $days = (int) $request->integer('days', 30);

        return $this->successResponse([
            'dashboard' => $this->analytics->dashboard($days),
            'volume_by_day' => $this->analytics->volumeByDay($days),
            'volume_by_module' => $this->analytics->volumeByModuleAndDepartment($days),
            'decision_breakdown' => $this->analytics->decisionBreakdown($days),
            'escalation_breakdown' => $this->analytics->escalationBreakdown($days),
            'bottlenecks' => $this->analytics->bottlenecks((int) $request->integer('limit', 10)),
            'workload' => $this->analytics->workloadByApprover((int) $request->integer('limit', 10)),
        ]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $days = (int) $request->integer('days', 90);

        return $this->successResponse([
            'duration_by_definition' => $this->analytics->averageDurationByDefinition($days),
            'sla_compliance_by_definition' => $this->analytics->slaComplianceByDefinition($days),
            'volume' => $this->analytics->volumeByModuleAndDepartment($days),
            'workload' => $this->analytics->workloadByApprover(20),
            'decisions' => $this->analytics->decisionBreakdown($days),
        ]);
    }

    // ------------------------------------------------------------------ audit

    public function auditLog(Request $request): JsonResponse
    {
        $query = \App\Modules\Workflow\Models\WorkflowAuditLog::query()->with(['actor:id,name', 'instance:id,code,title']);

        if ($action = $request->string('action')->toString()) {
            $query->where('action', strtoupper($action));
        }

        if ($instance = $request->string('instance')->toString()) {
            $query->whereHas('instance', fn ($q) => $q->where('code', $instance));
        }

        if ($module = $request->string('module')->toString()) {
            $query->whereHas('instance', fn ($q) => $q->where('module', $module));
        }

        if ($from = $request->date('from')) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to = $request->date('to')) {
            $query->where('occurred_at', '<=', $to);
        }

        return $this->successResponse([
            'summary' => $this->audit->summary((int) $request->integer('days', 30)),
            'logs' => $query->orderByDesc('id')->paginate(min(200, (int) $request->integer('per_page', 50)))
                ->through(fn ($log) => [
                    'id' => $log->id,
                    'at' => $log->occurred_at?->toIso8601String(),
                    'action' => $log->action->value,
                    'instance' => $log->instance?->code,
                    'title' => $log->instance?->title,
                    'actor' => $log->actor?->name,
                    'actor_id' => $log->actor_id,
                    'reason' => $log->reason,
                    'before' => $log->before,
                    'after' => $log->after,
                    'ip_address' => $log->ip_address,
                    'hash' => $log->hash,
                    'previous_hash' => $log->previous_hash,
                ]),
        ]);
    }

    /** Verifikasi rantai audit (§48). */
    public function verifyAudit(Request $request): JsonResponse
    {
        $instanceId = $request->integer('instance_id') ?: null;

        return $this->successResponse(
            $this->audit->verifyChain($instanceId),
            'Verifikasi rantai audit.',
        );
    }

    // ------------------------------------------------------------- meta/health

    public function meta(): JsonResponse
    {
        $meta = $this->builder->meta();

        $meta['permissions'] = [
            'definition' => ['workflow.definition.view', 'workflow.definition.manage', 'workflow.version.publish'],
            'request' => ['workflow.request.create', 'workflow.request.view', 'workflow.request.submit', 'workflow.request.cancel'],
            'approval' => ['workflow.approval.view', 'workflow.approval.decide', 'workflow.approval.reassign', 'workflow.approval.override'],
            'admin' => ['workflow.delegation.manage', 'workflow.sla.manage', 'workflow.analytics.view', 'workflow.audit.view', 'workflow.settings.manage', 'workflow.directory.manage'],
        ];

        return $this->successResponse($meta);
    }

    /** Kesehatan modul: registri terisi, kalender ada, tidak ada step tersangkut (§57). */
    public function health(): JsonResponse
    {
        $blocked = \App\Modules\Workflow\Models\WorkflowStepInstance::query()
            ->where('status', 'WAITING_FOR_APPROVER')
            ->count();

        $failed = WorkflowInstance::query()->where('status', 'FAILED')->count();

        $overdue = WorkflowSlaRecord::query()->whereIn('status', ['OVERDUE', 'ESCALATED'])->count();

        $checks = [
            'registrar_resolvers' => count($this->registrar->resolvers()),
            'registrar_actions' => count($this->registrar->actions()),
            'context_providers' => array_keys($this->registrar->contextProviders()),
            'calendars' => WorkflowCalendar::query()->where('is_active', true)->count(),
            'steps_waiting_for_approver' => $blocked,
            'instances_failed' => $failed,
            'sla_overdue_open' => $overdue,
            'audit_chain_valid' => $this->audit->verifyChain(null, 500)['valid'],
        ];

        return $this->successResponse([
            'healthy' => $checks['calendars'] > 0 && $checks['registrar_resolvers'] > 0,
            'checks' => $checks,
            'queues' => [
                'pending_approvals' => \App\Modules\Workflow\Models\WorkflowApproval::query()->where('status', 'PENDING')->count(),
                'open_instances' => WorkflowInstance::query()->open()->count(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    protected function describeDelegation(WorkflowDelegation $delegation): array
    {
        return [
            'id' => $delegation->id,
            'delegator_id' => $delegation->delegator_id,
            'delegator' => $delegation->delegator?->name,
            'delegate_id' => $delegation->delegate_id,
            'delegate' => $delegation->delegate?->name,
            'scope' => $delegation->scope,
            'reason' => $delegation->reason,
            'start_at' => $delegation->start_at?->toIso8601String(),
            'end_at' => $delegation->end_at?->toIso8601String(),
            'status' => $delegation->status->value,
            'status_label' => $delegation->status->label(),
            'activated_at' => $delegation->activated_at?->toIso8601String(),
            'revoked_at' => $delegation->revoked_at?->toIso8601String(),
            'revoke_reason' => $delegation->revoke_reason,
            'is_active_now' => $delegation->status === DelegationStatus::ACTIVE && $delegation->isActiveNow(),
        ];
    }
}
