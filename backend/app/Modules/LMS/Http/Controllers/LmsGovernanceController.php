<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\LmsAuditService;
use App\Modules\LMS\Application\Services\LmsGateway;
use App\Modules\LMS\Application\Services\LmsIntegrityService;
use App\Modules\LMS\Application\Services\SystemHealthService;
use App\Modules\LMS\Http\LmsController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tata kelola LMS: audit trail, integritas data, dan kesehatan sistem
 * (§23, §24, §42).
 */
class LmsGovernanceController extends LmsController
{
    public function __construct(
        private readonly LmsAuditService $audit,
        private readonly LmsIntegrityService $integrity,
        private readonly SystemHealthService $health,
        private readonly LmsGateway $gateway,
    ) {}

    /** GET /lms/health */
    public function health(Request $request): JsonResponse
    {
        $actor = $this->optionalActor($request);

        if ($actor !== null && ($actor->can('lms.health.view') || $actor->can('lms.manage'))) {
            return $this->successResponse($this->health->check());
        }

        // Ringkasan publik tanpa detail infrastruktur (§42).
        return $this->successResponse($this->health->publicSummary());
    }

    /** GET /lms/integration */
    public function integration(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.health.view') || $actor->can('lms.manage'), 'Anda tidak berhak melihat status integrasi LMS.');

        return $this->successResponse([
            'mode' => $this->gateway->mode(),
            'providers' => $this->gateway->describe(),
            'notifications' => $this->gateway->notifications()->status(),
        ]);
    }

    /** GET /lms/audit */
    public function audit(Request $request): JsonResponse
    {
        $this->authorizeAudit();

        return $this->successResponse(
            $this->audit->search($this->auditFilters($request), (int) $this->perPage($request, 25)),
            'Riwayat audit LMS.',
        );
    }

    /** GET /lms/audit/summary */
    public function auditSummary(Request $request): JsonResponse
    {
        $this->authorizeAudit();

        return $this->successResponse($this->audit->summary(
            $request->filled('from') ? (string) $request->query('from') : null,
            $request->filled('to') ? (string) $request->query('to') : null,
        ));
    }

    /** GET /lms/audit/entities/{type}/{id} */
    public function auditForEntity(Request $request, string $type, int $id): JsonResponse
    {
        $this->authorizeAudit();

        return $this->successResponse([
            'entries' => $this->audit->forEntity(str_replace('~', '\\', $type), $id, (int) $this->perPage($request, 50)),
        ]);
    }

    /** GET /lms/integrity */
    public function integrityChecks(Request $request): JsonResponse
    {
        $this->authorizeIntegrity();

        return $this->successResponse([
            'checks' => $this->integrity->checks(),
            'history' => $this->integrity->history(10),
        ]);
    }

    /** POST /lms/integrity/run */
    public function runIntegrity(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeIntegrity();

        $data = $request->validate([
            'scope' => ['nullable', 'string', 'max:40'],
            'persist' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse(
            $this->integrity->run($actor, (string) ($data['scope'] ?? 'all'), (bool) ($data['persist'] ?? true)),
            'Pemeriksaan integritas selesai.',
        );
    }

    /** GET /lms/integrity/repair-suggestions */
    public function repairSuggestions(Request $request): JsonResponse
    {
        $this->authorizeIntegrity();

        $suggestions = [];

        foreach ($this->integrity->checks() as $check) {
            $suggestions[$check['key']] = $this->integrity->repairSuggestion($check['key']) + ['label' => $check['label']];
        }

        return $this->successResponse(['suggestions' => $suggestions]);
    }

    /** @return array<string, mixed> */
    private function auditFilters(Request $request): array
    {
        return array_filter([
            'action' => $request->query('action'),
            'entity_type' => $request->query('entity_type'),
            'entity_id' => $request->query('entity_id'),
            'course_id' => $request->query('course_id'),
            'actor_id' => $request->query('actor_id'),
            'correlation_id' => $request->query('correlation_id'),
            'search' => $request->query('search'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function authorizeAudit(): void
    {
        $actor = $this->actor(request());

        $this->authorizeLms($actor->can('lms.audit.view') || $actor->can('lms.manage'), 'Anda tidak berhak membuka audit LMS.');
    }

    private function authorizeIntegrity(): void
    {
        $actor = $this->actor(request());

        $this->authorizeLms($actor->can('lms.integrity.run') || $actor->can('lms.manage'), 'Anda tidak berhak menjalankan pemeriksaan integritas.');
    }
}
