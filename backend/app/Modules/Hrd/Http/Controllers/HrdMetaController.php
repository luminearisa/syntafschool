<?php

namespace App\Modules\Hrd\Http\Controllers;

use App\Models\User;
use App\Modules\Hrd\Application\Services\DashboardService;
use App\Modules\Hrd\Application\Services\DataQualityService;
use App\Modules\Hrd\Application\Services\HrdAccessService;
use App\Modules\Hrd\Application\Services\HrdCalendarService;
use App\Modules\Hrd\Application\Services\HrdNotificationService;
use App\Modules\Hrd\Application\Services\HrdQueryService;
use App\Modules\Hrd\Application\Services\HrdWorkflowService;
use App\Modules\Hrd\Application\Services\ReferenceService;
use App\Modules\Hrd\Application\Support\HrdSettingsService;
use App\Modules\Hrd\Application\Support\IntegrationOutbox;
use App\Modules\Hrd\Http\Controllers\Concerns\RespondsWithHrd;
use App\Modules\Hrd\Models\HrAuditLog;
use App\Modules\Hrd\Models\HrNotification;
use App\Modules\Hrd\Models\HrReferenceItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Konteks, metadata, dasbor, dan administrasi HRD (§47, §71, §74, §81).
 *
 * Controller ini sengaja tipis: seluruh perhitungan ada di layanan. Yang menarik
 * di sini hanya satu hal — `me()` memberi tahu frontend **apa yang boleh
 * dilakukan pengguna ini**, sehingga layar tidak menawarkan tombol yang akan
 * ditolak server.
 */
class HrdMetaController
{
    use RespondsWithHrd;

    public function __construct(
        private readonly HrdAccessService $access,
        private readonly ReferenceService $references,
        private readonly DashboardService $dashboard,
        private readonly HrdCalendarService $calendar,
        private readonly HrdNotificationService $notifications,
        private readonly HrdQueryService $query,
        private readonly DataQualityService $quality,
        private readonly IntegrationOutbox $outbox,
        private readonly HrdWorkflowService $workflow,
        private readonly HrdSettingsService $settings,
    ) {}

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $employee = $this->access->employeeForUser($user);

        return $this->ok([
            'user' => ['id' => $user->getKey(), 'name' => $user->name, 'roles' => $user->getRoleNames()->all()],
            'employee' => $employee === null ? null : [
                'id' => (int) $employee->getKey(),
                'employee_number' => $employee->employee_number,
                'name' => $employee->displayName(),
            ],
            'scope' => [
                'level' => $this->access->scopeLevel($user),
                'organizations' => $this->access->visibleOrganizationIds($user),
                'can_view_sensitive' => $this->access->canViewSensitive($user),
            ],
            'capabilities' => [
                'employee.create' => $this->access->can($user, 'hr.employee.create'),
                'employee.update' => $this->access->can($user, 'hr.employee.update'),
                'employee.view_all' => $this->access->isGlobal($user),
                'lifecycle.create' => $this->access->can($user, 'hr.lifecycle.create'),
                'lifecycle.approve' => $this->access->can($user, 'hr.lifecycle.approve'),
                'lifecycle.execute' => $this->access->can($user, 'hr.lifecycle.execute'),
                'document.verify' => $this->access->can($user, 'hr.document.verify'),
                'report.export' => $this->access->can($user, 'hr.report.export'),
                'audit.view' => $this->access->can($user, 'hr.audit.view'),
                'settings.manage' => $this->access->can($user, 'hr.settings.manage'),
                'team.view' => $this->access->can($user, 'hr.team.view'),
            ],
            'unread_notifications' => $this->notifications->unreadCount($user),
        ]);
    }

    /** Pilihan-pilihan yang dipakai formulir (dari referensi, bukan enum di kode). */
    public function metadata(): JsonResponse
    {
        $groups = ['organization_type', 'worker_type', 'employment_type', 'employee_status', 'exit_reason', 'relationship_type', 'education_level', 'skill_proficiency', 'position_type', 'document_category', 'assignment_type', 'transaction_reason'];

        $references = [];

        foreach ($groups as $group) {
            $references[$group] = $this->references->items($group);
        }

        return $this->ok([
            'references' => $references,
            'relationship_types' => $references['relationship_type'],
            'transaction_types' => \App\Modules\Hrd\Models\HrTransactionType::query()->active()->orderBy('sort_order')->get(['code', 'name', 'category', 'requires_approval', 'requires_effective_date', 'requires_attachment', 'requires_reason']),
            'request_types' => \App\Modules\Hrd\Models\HrRequestType::query()->active()->orderBy('sort_order')->get(['code', 'name', 'category', 'requires_approval', 'requires_attachment', 'auto_apply_on_approval', 'editable_fields']),
            'document_types' => \App\Modules\Hrd\Models\HrDocumentType::query()->active()->ordered()->get(['id', 'code', 'name', 'category', 'requires_number', 'requires_expiry', 'is_mandatory', 'default_visibility']),
            'contract_types' => \App\Modules\Hrd\Models\HrContractType::query()->active()->get(['id', 'code', 'name', 'is_fixed_term', 'default_duration_months']),
            'skills' => \App\Modules\Hrd\Models\HrSkill::query()->active()->orderBy('name')->get(['id', 'code', 'name', 'category']),
            'certifications' => \App\Modules\Hrd\Models\HrCertification::query()->active()->orderBy('name')->get(['id', 'code', 'name', 'category', 'requires_expiry', 'is_mandatory_for_teachers']),
            'lifecycle_states' => collect(\App\Modules\Hrd\Domain\Enums\EmployeeLifecycleState::cases())->map(fn ($case) => ['value' => $case->value, 'label' => $case->label()]),
            'query_intents' => $this->query->supportedIntents(),
            'workflow_available' => $this->workflow->isEngineAvailable(),
        ]);
    }

    public function referenceItems(string $group): JsonResponse
    {
        return $this->ok($this->references->items($group));
    }

    public function upsertReferenceItem(Request $request, string $group): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'label' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:300'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
        ]);

        $item = $this->references->upsertItem($group, $data['code'], $data);

        return $this->ok($item, 201);
    }

    public function deactivateReferenceItem(string $group, string $code): JsonResponse
    {
        return $this->ok(['deactivated' => $this->references->deactivateItem($group, $code)]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Cakupan dasbor mengikuti cakupan pembaca: HR unit hanya melihat unitnya.
        $organizationIds = $this->access->visibleOrganizationIds($user);
        $organizationId = $request->integer('organization_id') ?: null;

        if ($organizationId !== null && $organizationIds !== null && ! in_array($organizationId, $organizationIds, true)) {
            abort(403, 'Unit ini di luar cakupan akses Anda.');
        }

        return $this->ok($this->dashboard->overview($organizationId));
    }

    public function alerts(Request $request): JsonResponse
    {
        return $this->ok($this->dashboard->alerts($request->integer('organization_id') ?: null));
    }

    public function health(): JsonResponse
    {
        return $this->ok($this->dashboard->health() + ['outbox_status' => $this->outbox->status()]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $from = $request->string('from')->toString() ?: now()->startOfMonth()->toDateString();
        $to = $request->string('to')->toString() ?: now()->endOfMonth()->toDateString();

        return $this->ok($this->calendar->between(
            $from,
            $to,
            $request->integer('organization_id') ?: null,
            $request->integer('employee_id') ?: null,
            (array) $request->input('types', []),
        ));
    }

    public function notifications(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->ok([
            'items' => $this->notifications->inbox($user),
            'unread' => $this->notifications->unreadCount($user),
        ]);
    }

    public function markNotificationRead(Request $request, HrNotification $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->ok(['read' => $this->notifications->markRead($user, (int) $notification->getKey())]);
    }

    /** Pertanyaan terstruktur yang sadar izin (§83) — bukan chatbot yang menghias. */
    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'intent' => ['required', 'string', 'max:60'],
            'parameters' => ['nullable', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $result = $this->query->ask($data['intent'], (array) ($data['parameters'] ?? []), $user);

        return $this->ok($result + ['answer_text' => $this->query->humanize($result)]);
    }

    public function audit(Request $request): JsonResponse
    {
        $logs = HrAuditLog::query()
            ->with(['actor:id,name', 'employee.person:id,full_name'])
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', '%'.$request->string('action')->toString().'%'))
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->string('entity_type')->toString()))
            ->when($request->filled('actor_id'), fn ($q) => $q->where('actor_id', $request->integer('actor_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('occurred_at', '>=', $request->string('from')->toString()))
            ->when($request->filled('to'), fn ($q) => $q->where('occurred_at', '<=', $request->string('to')->toString().' 23:59:59'))
            ->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return $this->ok($logs);
    }

    public function settings(): JsonResponse
    {
        return $this->ok([
            'effective' => [
                'numbering' => config('hrd.numbering'),
                'reminders' => config('hrd.reminders'),
                'expiry_warning_days' => $this->settings->integer('expiry_warning_days', 60),
                'documents' => config('hrd.documents'),
                'validation' => config('hrd.validation'),
                'scopes' => config('hrd.scopes'),
                'workflows' => config('hrd.workflows'),
                'legacy_bridge' => config('hrd.legacy_bridge.enabled'),
            ],
            'overrides' => \App\Modules\Hrd\Models\HrSetting::query()->orderBy('key')->get(),
            'validation_rules' => \App\Modules\Hrd\Models\HrValidationRule::query()->orderBy('code')->get(),
            'data_quality_handlers' => $this->quality->supportedHandlers(),
            'number_sequences' => \App\Modules\Hrd\Models\HrNumberSequence::query()->orderBy('scope')->get(),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:100'],
            'value' => ['required'],
            'organization_id' => ['nullable', 'integer', 'exists:hr_organizations,id'],
            'description' => ['nullable', 'string', 'max:250'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $setting = $this->settings->set(
            $data['key'],
            $data['value'],
            $data['organization_id'] ?? null,
            $user->getKey(),
            $data['description'] ?? null,
        );

        return $this->ok($setting);
    }

    /** @return \Illuminate\Support\Collection<int, HrReferenceItem> */
    public function referenceGroupSummary(): \Illuminate\Support\Collection
    {
        return $this->references->groups();
    }
}
