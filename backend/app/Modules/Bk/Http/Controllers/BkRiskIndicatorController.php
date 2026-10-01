<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Modules\Bk\Application\Services\BkAuditService;
use App\Modules\Bk\Application\Services\BkEarlyWarningEngine;
use App\Modules\Bk\Application\Services\BkRiskIndicatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penanda dukungan/risiko & mesin peringatan dini.
 *
 * Endpoint di sini hanya untuk staf BK: daftar penanda menyebut siswa secara
 * individual, dan itu bukan informasi untuk guru atau wali.
 */
class BkRiskIndicatorController extends Controller
{
    use ApiResponse;


    public function __construct(
        private readonly BkRiskIndicatorService $indicators,
        private readonly BkEarlyWarningEngine $engine,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('bk.risk.view') || abort(403);

        return $this->successResponse($this->indicators->paginate($request->user(), $request->only([
            'student_id', 'status', 'code', 'severity', 'source', 'from', 'to',
        ]), (int) $request->input('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('bk.risk.manage') || abort(403);

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'indicator_code' => ['required', 'string'],
            'indicator_type' => ['nullable', 'string'],
            'severity' => ['nullable', 'string'],
            'source_module' => ['nullable', 'string'],
            'summary' => ['nullable', 'string'],
            'observed_value' => ['nullable', 'numeric'],
            'evidence' => ['nullable', 'array'],
        ]);

        $indicator = $this->indicators->create(
            actor: $request->user(),
            studentId: (int) $data['student_id'],
            code: \App\Modules\Bk\Domain\Enums\RiskIndicatorCode::from($data['indicator_code']),
            attributes: $data,
            type: \App\Modules\Bk\Domain\Enums\RiskIndicatorType::tryFrom((string) ($data['indicator_type'] ?? '')) ?? null,
            source: $data['source_module'] ?? null,
        );

        return $this->createdResponse($indicator, 'Penanda dukungan dicatat. Penanda bukan diagnosis dan menunggu tinjauan manusia.');
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.risk.manage') || abort(403);

        $indicator = \App\Modules\Bk\Models\BkRiskIndicator::query()->findOrFail($id);

        $data = $request->validate([
            'status' => ['nullable', 'string'],
            'review_note' => ['nullable', 'string'],
            'case_id' => ['nullable', 'integer', 'exists:bk_cases,id'],
            'referral_id' => ['nullable', 'integer', 'exists:bk_referrals,id'],
        ]);

        return $this->successResponse($this->indicators->review($request->user(), $indicator, $data), 'Tinjauan penanda tersimpan.');
    }

    public function escalate(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.risk.manage') || abort(403);

        $indicator = \App\Modules\Bk\Models\BkRiskIndicator::query()->findOrFail($id);
        $data = $request->validate(['reason' => ['required', 'string']]);

        return $this->successResponse($this->indicators->escalateAttention($request->user(), $indicator, $data['reason']), 'Penanda dinaikkan perhatiannya.');
    }

    // -------------------------------------------------------- mesin aturan

    public function rules(Request $request): JsonResponse
    {
        $request->user()->can('bk.early-warning.view') || abort(403);

        return $this->successResponse([
            'rules' => \App\Modules\Bk\Models\BkEarlyWarningRule::query()
                ->when($request->input('active_only'), fn ($q) => $q->active())
                ->orderBy('code')
                ->orderByDesc('version')
                ->paginate((int) $request->input('per_page', 25)),
            'metrics' => \App\Modules\Bk\Domain\Enums\EarlyWarningMetric::options(),
            'operators' => \App\Modules\Bk\Domain\Enums\EarlyWarningOperator::options(),
            'actions' => \App\Modules\Bk\Domain\Enums\EarlyWarningAction::options(),
            'note' => 'Ambang disimpan sebagai data, bukan ditanam di kode: sekolah dapat menyesuaikannya tanpa deploy.',
        ]);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $request->user()->can('bk.early-warning.manage') || abort(403);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'metric' => ['required', 'string'],
            'operator' => ['required', 'string'],
            'threshold' => ['required', 'numeric'],
            'threshold_unit' => ['nullable', 'string', 'max:24'],
            'window_days' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'string'],
            'indicator_code' => ['nullable', 'string'],
            'severity' => ['nullable', 'string'],
            'cooldown_days' => ['nullable', 'integer', 'min:1'],
            'scope' => ['nullable', 'array'],
            'requires_review' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Validasi semantik: operator yang tidak masuk akal untuk metriknya
        // menghasilkan aturan yang tidak pernah cocok, dan itu kegagalan senyap.
        $metric = \App\Modules\Bk\Domain\Enums\EarlyWarningMetric::from($data['metric']);
        $operator = \App\Modules\Bk\Domain\Enums\EarlyWarningOperator::from($data['operator']);

        if ($metric->higherIsBetter() && in_array($operator, [\App\Modules\Bk\Domain\Enums\EarlyWarningOperator::GT, \App\Modules\Bk\Domain\Enums\EarlyWarningOperator::GTE], true)
            && (float) $data['threshold'] >= 100) {
            throw new \App\Modules\Bk\Exceptions\BkValidationException([
                'threshold' => ['Ambang di atas 100% tidak akan pernah cocok untuk metrik persentase.'],
            ]);
        }

        $version = (int) \App\Modules\Bk\Models\BkEarlyWarningRule::query()->where('code', $data['code'])->max('version') + 1;

        $rule = \App\Modules\Bk\Models\BkEarlyWarningRule::query()->create([
            ...$data,
            'version' => $version,
            'severity' => $data['severity'] ?? 'MODERATE',
            'cooldown_days' => $data['cooldown_days'] ?? 7,
            'requires_review' => $data['requires_review'] ?? true,
            'is_active' => $data['is_active'] ?? true,
            'is_system' => false,
            'created_by' => $request->user()->id,
        ]);

        app(BkAuditService::class)->record(\App\Modules\Bk\Domain\Enums\AuditAction::CREATE, $rule, $request->user(), [
            'code' => $rule->code,
            'version' => $version,
            'metric' => $rule->metric->value,
            'threshold' => (float) $rule->threshold,
        ]);

        return $this->createdResponse($rule, 'Aturan peringatan dini dibuat sebagai versi baru.');
    }

    public function toggleRule(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.early-warning.manage') || abort(403);

        $rule = \App\Modules\Bk\Models\BkEarlyWarningRule::query()->findOrFail($id);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $rule->forceFill(['is_active' => (bool) $data['is_active'], 'updated_by' => $request->user()->id])->save();

        app(BkAuditService::class)->record(\App\Modules\Bk\Domain\Enums\AuditAction::UPDATE, $rule, $request->user(), [
            'is_active' => $rule->is_active,
        ]);

        return $this->successResponse($rule, $rule->is_active ? 'Aturan diaktifkan.' : 'Aturan dinonaktifkan.');
    }

    public function events(Request $request): JsonResponse
    {
        $request->user()->can('bk.early-warning.view') || abort(403);

        return $this->successResponse(\App\Modules\Bk\Models\BkEarlyWarningEvent::query()
            ->with(['student:id,full_name', 'rule:id,code,name', 'indicator:id,indicator_code,severity'])
            ->when($request->input('student_id'), fn ($q, $id) => $q->where('student_id', (int) $id))
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('matched_only'), fn ($q) => $q->matched())
            ->when($request->input('from'), fn ($q, $from) => $q->whereDate('evaluation_date', '>=', $from))
            ->orderByDesc('evaluation_date')
            ->paginate((int) $request->input('per_page', 25)));
    }

    public function evaluate(Request $request): JsonResponse
    {
        $request->user()->can('bk.early-warning.run') || abort(403);

        $data = $request->validate([
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'queue' => ['nullable', 'boolean'],
        ]);

        if (! empty($data['student_id'])) {
            $events = $this->engine->evaluateStudent((int) $data['student_id'], $request->user());

            return $this->successResponse([
                'evaluated' => count($events),
                'events' => array_map(fn ($event): array => [
                    'rule' => $event->rule?->code,
                    'metric' => $event->metric?->value,
                    'observed' => $event->observed_value,
                    'threshold' => $event->threshold,
                    'status' => $event->status->value,
                    'reason' => $event->reason,
                    'indicator_id' => $event->indicator_id,
                ], $events),
            ], 'Evaluasi aturan dijalankan untuk siswa ini.');
        }

        \App\Modules\Bk\Jobs\BkEarlyWarningBatchJob::dispatch($request->user()->id);

        return $this->successResponse(['queued' => true], 'Evaluasi seluruh siswa dijadwalkan pada antrean.');
    }

    public function health(Request $request): JsonResponse
    {
        $request->user()->can('bk.settings.view') || abort(403);

        return $this->successResponse([
            'engine' => $this->engine->health(),
            'automation' => app(\App\Modules\Bk\Application\Services\BkAutomationService::class)->overview(),
        ]);
    }
}
