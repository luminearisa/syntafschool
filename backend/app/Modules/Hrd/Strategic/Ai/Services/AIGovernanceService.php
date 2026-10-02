<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Services;

use App\Modules\Hrd\Strategic\Ai\Models\HrAiAgent;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiAuditLog;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiCostLog;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiFeedback;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiModel;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiPolicy;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiProvider;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiRun;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiUsageLog;
use App\Modules\Hrd\Strategic\Ai\Models\HrBiasMonitoring;
use App\Modules\Hrd\Strategic\Forecasting\Models\HrModelRegistry;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use Illuminate\Support\Facades\DB;

/**
 * AI Governance (§70–§74, §110–§117): kebijakan, izin, audit, bias, biaya,
 * feedback, dan registry model. Termasuk penegakan aksi terlarang.
 */
class AIGovernanceService
{
    public function __construct(private readonly Phase3Context $ctx) {}

    public function definePolicy(array $data, mixed $actor = null): HrAiPolicy
    {
        $policy = HrAiPolicy::query()->updateOrCreate(
            ['code' => strtoupper((string) $data['code'])],
            [
                'name' => $data['name'], 'purpose' => $data['purpose'] ?? null,
                'rules' => $data['rules'] ?? [], 'applies_to' => $data['applies_to'] ?? [],
                'data_classification_limit' => strtoupper((string) ($data['data_classification_limit'] ?? 'CONFIDENTIAL')),
                'requires_human_review' => (bool) ($data['requires_human_review'] ?? true),
                'prohibited_actions' => $data['prohibited_actions'] ?? config('hrd_phase3.ai.prohibited_actions', []),
                'status' => 'ACTIVE', 'effective_from' => $data['effective_from'] ?? now()->toDateString(),
                'owner_role' => $data['owner_role'] ?? 'HR_DIRECTOR',
            ]
        );

        $this->ctx->audit('strategic.ai_policy.defined', HrAiPolicy::class, $policy->getKey(), ['code' => $policy->code], null, $actor);

        return $policy;
    }

    /**
     * Aksi yang dilarang untuk AI (§113). Selalu mengembalikan alasan, bukan
     * sekadar gagal senyap.
     *
     * @return array<string, mixed>
     */
    public function assertActionAllowed(string $actionType): array
    {
        $prohibited = (array) config('hrd_phase3.ai.prohibited_actions', []);
        foreach (HrAiPolicy::query()->where('status', 'ACTIVE')->get() as $policy) {
            $prohibited = array_values(array_unique(array_merge($prohibited, (array) $policy->prohibited_actions)));
        }
        $normalised = strtoupper($actionType);
        $blocked = in_array($normalised, $prohibited, true);

        return [
            'action' => $normalised, 'allowed' => ! $blocked,
            'reason' => $blocked ? 'Aksi termasuk daftar terlarang: keputusan berdampak pada pegawai harus diambil manusia.' : null,
            'policy_reference' => $blocked ? 'hrd_phase3.ai.prohibited_actions' : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordAudit(int $runId, string $action, string $actorType, ?string $actorReference = null, array $before = [], array $after = [], bool $approvalRequired = false, mixed $approver = null, ?string $notes = null): HrAiAuditLog
    {
        return HrAiAuditLog::query()->create([
            'run_id' => $runId > 0 ? $runId : null, 'action' => strtoupper($action),
            'actor_type' => strtoupper($actorType), 'actor_reference' => $actorReference,
            'subject_type' => $after['subject_type'] ?? null, 'subject_id' => $after['subject_id'] ?? null,
            'before_state' => $before, 'after_state' => $after,
            'approval_required' => $approvalRequired,
            'approved_by' => $approver ? $this->ctx->employeeFor($approver)?->getKey() : null,
            'approved_at' => $approver ? now() : null, 'notes' => $notes, 'occurred_at' => now(),
        ]);
    }

    public function feedback(int $runId, string $feedback, mixed $user, ?string $comment = null, ?int $recommendationId = null): HrAiFeedback
    {
        return HrAiFeedback::query()->create([
            'run_id' => $runId, 'recommendation_id' => $recommendationId,
            'user_id' => $this->ctx->actorId($user), 'feedback' => strtoupper($feedback), 'comment' => $comment,
        ]);
    }

    /**
     * Catat pemakaian & biaya (§117).
     *
     * @return array<string, mixed>
     */
    public function logUsage(?HrAiRun $run, string $feature, string $provider, string $model, int $tokensIn, int $tokensOut, int $latencyMs, mixed $user): array
    {
        $modelRow = HrAiModel::query()->where('code', $model)->first();
        $cost = $modelRow
            ? $this->ctx->money(($tokensIn / 1000) * (float) $modelRow->input_cost_per_1k + ($tokensOut / 1000) * (float) $modelRow->output_cost_per_1k)
            : 0.0;
        $period = now()->format('Y-m');

        HrAiUsageLog::query()->create([
            'user_id' => $this->ctx->actorId($user), 'feature' => $feature,
            'agent_code' => $run?->agent_code, 'run_id' => $run?->getKey(),
            'provider_code' => $provider, 'model_code' => $model,
            'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut, 'latency_ms' => $latencyMs,
            'cost' => $cost, 'currency' => 'IDR', 'occurred_at' => now(),
        ]);

        HrAiCostLog::query()->updateOrCreate(
            ['period' => $period, 'provider_code' => $provider, 'model_code' => $model, 'feature' => $feature, 'user_id' => $this->ctx->actorId($user)],
            [
                'requests' => (int) DB::table('hr_ai_usage_logs')->where('feature', $feature)
                    ->where('provider_code', $provider)->where('model_code', $model)->count(),
                'tokens' => $tokensIn + $tokensOut, 'cost' => $cost, 'currency' => 'IDR',
            ]
        );

        return ['cost' => $cost, 'feature' => $feature, 'model' => $model, 'currency' => 'IDR'];
    }

    /**
     * Dashboard biaya AI per fitur/pengguna/model (§117).
     *
     * @return array<string, mixed>
     */
    public function costDashboard(): array
    {
        $byFeature = DB::table('hr_ai_usage_logs')->selectRaw('feature, count(*) as requests, sum(tokens_in + tokens_out) as tokens, sum(cost) as cost')
            ->groupBy('feature')->get()->map(fn ($row): array => [
                'feature' => $row->feature, 'requests' => (int) $row->requests,
                'tokens' => (int) $row->tokens, 'cost' => $this->ctx->money((float) $row->cost),
            ])->all();
        $byUser = DB::table('hr_ai_usage_logs')->selectRaw('user_id, count(*) as requests, sum(cost) as cost')
            ->groupBy('user_id')->orderByDesc('cost')->limit(10)->get()->map(fn ($row): array => [
                'user_id' => $row->user_id, 'requests' => (int) $row->requests, 'cost' => $this->ctx->money((float) $row->cost),
            ])->all();

        return [
            'by_feature' => $byFeature, 'by_user' => $byUser,
            'total_cost' => $this->ctx->money((float) DB::table('hr_ai_usage_logs')->sum('cost')),
            'total_requests' => (int) DB::table('hr_ai_usage_logs')->count(),
            'routing' => (array) config('hrd_phase3.ai.routing', []),
            'note' => 'Pertanyaan sederhana diarahkan ke model kecil; data sensitif hanya ke model privat yang disetujui.',
        ];
    }

    /**
     * Pemantauan bias (§114): memeriksa pola rekomendasi antar kelompok,
     * TANPA menggunakan atribut terlindungi sebagai dasar keputusan.
     *
     * @return array<string, mixed>
     */
    public function monitorBias(string $period, string $context = 'RECRUITMENT'): array
    {
        $runs = HrAiRun::query()->where('created_at', 'like', $period.'%')->get();
        $total = max($runs->count(), 1);
        $rows = [];

        $byIntent = $runs->groupBy('intent')->map(fn ($group): array => ['count' => $group->count(), 'rate' => $this->ctx->percent($group->count(), $total)])->all();
        foreach ($byIntent as $intent => $data) {
            $flag = $data['rate'] > 80 ? 'REVIEW' : ($data['rate'] > 60 ? 'WATCH' : 'OK');
            $rows[] = HrBiasMonitoring::query()->updateOrCreate(
                ['model_code' => (string) ($runs->first()?->model_code ?? 'INTERNAL'), 'period' => $period,
                    'context' => strtoupper($context), 'group_dimension' => 'intent:'.$intent],
                [
                    'selection_rate' => $data['rate'], 'reference_rate' => $this->ctx->percent(1, count($byIntent) ?: 1),
                    'disparity_ratio' => round($data['rate'] / max($this->ctx->percent(1, count($byIntent) ?: 1), 1), 4),
                    'flag' => $flag,
                    'evidence' => ['intent' => $intent, 'count' => $data['count'], 'total_runs' => $runs->count()],
                    'notes' => 'Pemantauan distribusi rekomendasi; atribut terlindungi tidak dipakai sebagai fitur keputusan.',
                    'generated_at' => now(),
                ]
            );
        }

        return ['period' => $period, 'context' => strtoupper($context), 'findings' => $rows, 'runs' => $runs->count()];
    }

    /**
     * Registry model & agent (§74, §115).
     *
     * @return array<string, mixed>
     */
    public function registry(): array
    {
        return [
            'providers' => HrAiProvider::query()->get()->map(fn (HrAiProvider $row): array => [
                'code' => $row->code, 'type' => $row->provider_type, 'private' => (bool) $row->is_private,
                'approved_for_sensitive' => (bool) $row->approved_for_sensitive, 'status' => $row->status,
            ])->all(),
            'models' => HrAiModel::query()->get()->map(fn (HrAiModel $row): array => [
                'code' => $row->code, 'type' => $row->model_type, 'classification_limit' => $row->data_classification_limit,
                'latency' => $row->latency_class, 'active' => (bool) $row->is_active,
            ])->all(),
            'agents' => HrAiAgent::query()->get()->map(fn (HrAiAgent $row): array => [
                'code' => $row->code, 'group' => $row->agent_group, 'tools' => $row->allowed_tools,
                'requires_human_review' => (bool) $row->requires_human_review,
            ])->all(),
            'ml_models' => HrModelRegistry::query()->get()->map(fn (HrModelRegistry $row): array => [
                'code' => $row->code, 'type' => $row->model_type, 'version' => $row->version, 'status' => $row->status,
            ])->all(),
            'policies' => HrAiPolicy::query()->where('status', 'ACTIVE')->pluck('code')->all(),
        ];
    }

    /**
     * Redaksi payload sebelum keluar ke provider (§72).
     *
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        $blocked = (array) config('hrd_phase3.ai.blocked_fields', []);

        return app(AIToolRegistry::class)->mask($payload, $blocked);
    }

    /**
     * Penjelasan yang dapat diaudit (§70).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function explain(array $context): array
    {
        return [
            'summary' => $context['summary'] ?? null,
            'contributing_signals' => $context['signals'] ?? [],
            'data_period' => $context['period'] ?? null,
            'data_sources' => $context['sources'] ?? [],
            'calculation' => $context['calculation'] ?? null,
            'assumptions' => $context['assumptions'] ?? [],
            'confidence' => $context['confidence'] ?? null,
            'limitations' => $context['limitations'] ?? ['Model tidak menggantikan penilaian manusia.'],
        ];
    }
}
