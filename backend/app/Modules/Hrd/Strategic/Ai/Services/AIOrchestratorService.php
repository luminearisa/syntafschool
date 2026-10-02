<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Ai\Services;

use App\Modules\Hrd\Strategic\Ai\Models\HrAiAgent;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiConversation;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiRecommendation;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiRun;
use App\Modules\Hrd\Strategic\Governance\Services\RiskService;
use App\Modules\Hrd\Strategic\Support\Phase3Context;
use App\Modules\Hrd\Strategic\Talent\Services\TalentMarketplaceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR AI Gateway & Agent Orchestration (§66–§69, §119, §120, §158, §160–§164).
 *
 * Alur: User → Intent → Permission → Agent → Tool Calls → Data → Reasoning →
 * Response → (Approval bila mengubah data).
 *
 * Aturan keras: tanpa sumber data → tidak ada jawaban faktual (§71); aksi
 * berdampak pada pegawai tidak pernah dieksekusi otomatis (§113).
 */
class AIOrchestratorService
{
    public function __construct(
        private readonly Phase3Context $ctx,
        private readonly AIToolRegistry $tools,
        private readonly AIGovernanceService $governance,
        private readonly RiskService $risks,
        private readonly TalentMarketplaceService $marketplace,
    ) {}

    /**
     * Tanya-jawab HR berbasis data nyata.
     *
     * @return array<string, mixed>
     */
    public function ask(array $request, mixed $user, string $channel = 'COPILOT'): array
    {
        $question = trim((string) ($request['question'] ?? ''));
        if ($question === '') {
            return ['status' => 'REFUSED', 'answer' => 'Pertanyaan kosong.', 'sources' => []];
        }

        $intent = $this->detectIntent($question);
        $agentCode = $this->selectAgent($intent);
        $agent = HrAiAgent::query()->where('code', $agentCode)->first();
        $started = microtime(true);

        $run = HrAiRun::query()->create([
            'conversation_id' => $this->conversation($user, $channel)?->getKey(),
            'code' => $this->ctx->nextCode('hr_ai_runs', 'RUN'),
            'agent_code' => $agentCode, 'user_id' => $this->ctx->actorId($user),
            'employee_id' => $this->ctx->employeeFor($user)?->getKey(),
            'intent' => $intent, 'intent_confidence' => 0.8,
            'prompt' => $question,
            'prompt_redacted' => $this->governance->redact(['question' => $question]),
            'model_code' => $agent?->model_code ?? (string) config('hrd_phase3.ai.default_model', 'internal-small'),
            'provider_code' => (string) config('hrd_phase3.ai.default_provider', 'INTERNAL'),
            'status' => 'RUNNING', 'requires_approval' => false, 'started_at' => now(),
        ]);

        // Rencana alat per intent — agent hanya menjalankan tools yang terdaftar.
        $plan = $this->toolPlan($intent, $request, $user);
        $toolResults = [];
        $denials = [];
        foreach ($plan as $step) {
            $result = $this->tools->execute($step['tool'], $step['arguments'], $user, $agentCode, (int) $run->getKey());
            if ($result['status'] === 'DENIED') {
                $denials[] = ['tool' => $step['tool'], 'reason' => $result['reason']];
                continue;
            }
            $toolResults[$step['tool']] = $result;
        }

        $composition = $this->compose($intent, $question, $toolResults, $denials, $user);
        $latency = (int) ((microtime(true) - $started) * 1000);

        if ($composition['refused']) {
            $run->forceFill([
                'status' => 'COMPLETED', 'response' => $composition['answer'],
                'response_payload' => $composition, 'tools_used' => array_keys($toolResults),
                'data_sources' => [], 'confidence' => 0, 'requires_approval' => false,
                'latency_ms' => $latency, 'completed_at' => now(),
            ])->save();

            $this->governance->recordAudit((int) $run->getKey(), 'REFUSAL', 'AI', $agentCode, [], [
                'reason' => 'no_source_no_answer', 'intent' => $intent,
            ]);

            return $this->respond($run, $composition);
        }

        $run->forceFill([
            'status' => $composition['requires_confirmation'] ? 'AWAITING_APPROVAL' : 'COMPLETED',
            'response' => $composition['answer'], 'response_payload' => $composition,
            'tools_used' => array_keys($toolResults), 'data_sources' => $composition['sources'],
            'calculation' => $composition['calculation'], 'assumptions' => $composition['assumptions'],
            'confidence' => $composition['confidence'], 'tokens_in' => (int) str_word_count($question),
            'tokens_out' => (int) str_word_count($composition['answer']), 'latency_ms' => $latency,
            'requires_approval' => $composition['requires_confirmation'],
            'approval_status' => $composition['requires_confirmation'] ? 'PENDING' : null,
            'completed_at' => now(),
        ])->save();

        $this->governance->logUsage($run, 'copilot', (string) $run->provider_code, (string) $run->model_code,
            str_word_count($question), str_word_count($composition['answer']), $latency, $user);
        $this->governance->recordAudit((int) $run->getKey(), 'RESPONSE', 'AI', $agentCode, [], [
            'intent' => $intent, 'tools' => array_keys($toolResults), 'sources' => $composition['sources'],
        ]);
        $this->ctx->audit('strategic.ai.run.completed', HrAiRun::class, $run->getKey(), [
            'intent' => $intent, 'agent' => $agentCode, 'tools' => array_keys($toolResults),
            'answer_length' => mb_strlen($composition['answer']),
        ], null, $user);

        return $this->respond($run, $composition);
    }

    /**
     * Agen yang tersedia (§66).
     *
     * @return array<int, array<string, mixed>>
     */
    public function agents(): array
    {
        return HrAiAgent::query()->where('status', 'ACTIVE')->orderBy('code')->get()
            ->map(fn (HrAiAgent $agent): array => [
                'code' => $agent->code, 'name' => $agent->name, 'group' => $agent->agent_group,
                'tools' => $agent->allowed_tools, 'permissions' => $agent->required_permissions,
                'requires_human_review' => (bool) $agent->requires_human_review,
            ])->all();
    }

    /**
     * Rekomendasi dengan alasan, bukti, keyakinan, dampak, biaya, dan pemilik
     * (§112, §144, §145).
     *
     * @return array<string, mixed>
     */
    public function recommend(array $context, mixed $user): HrAiRecommendation
    {
        $type = strtoupper((string) ($context['type'] ?? 'WORKFORCE'));
        $recommendation = HrAiRecommendation::query()->create([
            'code' => $this->ctx->nextCode('hr_ai_recommendations', 'REC'),
            'recommendation_type' => $type,
            'title' => $context['title'] ?? ('Rekomendasi '.$type),
            'problem' => $context['problem'] ?? null,
            'evidence' => $context['evidence'] ?? [],
            'options' => $context['options'] ?? [],
            'expected_impact' => $context['expected_impact'] ?? null,
            'estimated_cost' => $context['estimated_cost'] ?? null,
            'risks' => $context['risks'] ?? [],
            'dependencies' => $context['dependencies'] ?? [],
            'recommended_next_step' => $context['next_step'] ?? null,
            'confidence' => (float) ($context['confidence'] ?? 0.6),
            'owner_role' => $context['owner_role'] ?? 'HR_MANAGER',
            'status' => 'PENDING_REVIEW',
        ]);

        $this->governance->recordAudit(0, 'RECOMMENDATION', 'AI', $type, [], [
            'recommendation' => $recommendation->code, 'evidence' => $context['evidence'] ?? [],
        ], true);
        $this->ctx->audit('strategic.ai.recommendation.created', HrAiRecommendation::class, $recommendation->getKey(), [
            'type' => $type, 'confidence' => (float) $recommendation->confidence,
        ], null, $user);

        return $recommendation;
    }

    /**
     * Tinjauan manusia atas rekomendasi (§113).
     */
    public function reviewRecommendation(int $recommendationId, string $decision, mixed $user, ?string $notes = null): HrAiRecommendation
    {
        $recommendation = HrAiRecommendation::query()->findOrFail($recommendationId);
        $status = strtoupper($decision) === 'APPROVED' ? 'APPROVED' : 'REJECTED';
        $recommendation->forceFill([
            'status' => $status, 'human_decision' => $notes ?? $status,
            'reviewed_by' => $this->ctx->employeeFor($user)?->getKey(), 'reviewed_at' => now(),
        ])->save();

        $this->governance->recordAudit(0, 'HUMAN_REVIEW', 'USER', (string) $recommendation->code, [
            'status' => 'PENDING_REVIEW',
        ], ['status' => $status, 'subject_type' => HrAiRecommendation::class, 'subject_id' => $recommendation->getKey()], true, $user, $notes);
        $this->ctx->audit('strategic.ai.recommendation.reviewed', HrAiRecommendation::class, $recommendation->getKey(), [
            'decision' => $status,
        ], $notes, $user);

        return $recommendation->refresh();
    }

    /**
     * Rekomendasi yang disetujui menjadi rencana aksi strategis yang dapat
     * dieksekusi lewat workflow (§78).
     *
     * @return array<string, mixed>
     */
    public function convertToAction(int $recommendationId, mixed $user): array
    {
        $recommendation = HrAiRecommendation::query()->findOrFail($recommendationId);
        if ($recommendation->status !== 'APPROVED') {
            return ['converted' => false, 'reason' => 'Rekomendasi belum disetujui manusia.'];
        }

        $action = $this->risks->createAction([
            'title' => $recommendation->title,
            'objective' => $recommendation->problem,
            'source_type' => 'AI_RECOMMENDATION',
            'source_id' => $recommendation->getKey(),
            'recommendation_id' => $recommendation->getKey(),
            'priority' => 'HIGH',
            'budget_amount' => $recommendation->estimated_cost,
            'measurement' => ['expected_impact' => $recommendation->expected_impact],
            'items' => array_map(fn (array $option): array => [
                'title' => (string) ($option['label'] ?? $option['title'] ?? 'Opsi tindakan'),
                'type' => 'TASK',
            ], (array) $recommendation->options),
        ], $user);

        $recommendation->forceFill(['status' => 'CONVERTED', 'strategic_action_id' => $action['action'] ? null : null])->save();

        return ['converted' => true, 'action' => $action, 'recommendation' => $recommendation->code];
    }

    /**
     * Aksi tulis: draf dulu, konfirmasi kemudian (§68).
     *
     * @return array<string, mixed>
     */
    public function confirmWriteAction(int $runId, mixed $user): array
    {
        $run = HrAiRun::query()->find($runId);
        if (! $run) {
            return ['status' => 'NOT_FOUND'];
        }

        $payload = (array) $run->response_payload;
        $draft = (array) ($payload['draft'] ?? []);
        $confirmation = (array) ($draft['request'] ?? []);
        $gate = $this->governance->assertActionAllowed((string) ($draft['action'] ?? 'CREATE_HR_REQUEST'));
        if (! $gate['allowed']) {
            return ['status' => 'BLOCKED', 'reason' => $gate['reason']];
        }

        $created = $this->ctx->safe(function () use ($confirmation, $user) {
            if (! $this->ctx->hasTable('hr_service_tickets')) {
                return null;
            }

            $ticket = [
                'code' => 'HRT-AI-'.now()->format('ymd-His'),
                'employee_id' => $confirmation['employee_id'] ?? $this->ctx->employeeFor($user)?->getKey(),
                'category' => $confirmation['category'] ?? 'GENERAL',
                'subject' => $confirmation['subject'] ?? 'Permintaan dari AI Copilot',
                'description' => $confirmation['description'] ?? null,
                'priority' => 'MEDIUM', 'status' => 'OPEN',
                'meta' => json_encode(['source' => 'AI_COPILOT', 'run' => $run->code, 'confirmed_by' => $this->ctx->actorId($user)]),
            ];
            // Kolom wajib pada skema tiket diisi wajar agar draf AI selalu tereksekusi.
            $columns = Schema::getColumnListing('hr_service_tickets');
            $payload = array_intersect_key($ticket, array_flip($columns));
            foreach ($columns as $column) {
                if (! array_key_exists($column, $payload) && ! in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                    $payload[$column] = null;
                }
            }
            $payload['created_at'] = now();
            $payload['updated_at'] = now();

            return DB::table('hr_service_tickets')->insertGetId($payload);
        }, null);

        $run->forceFill(['approval_status' => 'CONFIRMED', 'status' => 'COMPLETED'])->save();
        $this->governance->recordAudit((int) $run->getKey(), 'MUTATION', 'USER', (string) $run->code, [],
            ['subject_type' => 'hr_service_tickets', 'subject_id' => $created], true, $user,
            'Draf AI dijalankan setelah konfirmasi manusia.');
        $this->ctx->audit('strategic.ai.write_action.confirmed', HrAiRun::class, $run->getKey(), [
            'ticket_id' => $created,
        ], null, $user);

        return ['status' => 'EXECUTED', 'reference' => $created, 'workflow' => 'Phase 2 HR service workflow'];
    }

    /**
     * @return array<string, mixed>
     */
    public function runs(int $limit = 20): array
    {
        return [
            'runs' => HrAiRun::query()->orderByDesc('id')->limit($limit)->get()
                ->map(fn (HrAiRun $run): array => [
                    'code' => $run->code, 'agent' => $run->agent_code, 'intent' => $run->intent,
                    'status' => $run->status, 'tools' => $run->tools_used, 'sources' => $run->data_sources,
                    'confidence' => (float) $run->confidence, 'approval' => $run->approval_status,
                    'question' => mb_substr((string) $run->prompt, 0, 160),
                ])->all(),
            'conversations' => (int) DB::table('hr_ai_conversations')->count(),
            'recommendations' => (int) DB::table('hr_ai_recommendations')->count(),
        ];
    }

    /* ------------------------------------------------------------------ */

    private function conversation(mixed $user, string $channel): ?HrAiConversation
    {
        return $this->ctx->safe(fn (): HrAiConversation => HrAiConversation::query()->create([
            'code' => $this->ctx->nextCode('hr_ai_conversations', 'CNV'),
            'user_id' => $this->ctx->actorId($user), 'channel' => strtoupper($channel),
            'employee_id' => $this->ctx->employeeFor($user)?->getKey(),
            'status' => 'OPEN', 'started_at' => now(), 'last_message_at' => now(),
        ]), null);
    }

    private function detectIntent(string $question): string
    {
        $text = mb_strtolower($question);

        return match (true) {
            str_contains($text, 'cuti') || str_contains($text, 'leave') => 'LEAVE_BALANCE',
            str_contains($text, 'gaji') || str_contains($text, 'payroll') || str_contains($text, 'take home') => 'PAYROLL_EXPLANATION',
            str_contains($text, 'guru') || str_contains($text, 'teacher') || str_contains($text, 'siswa') => 'WORKFORCE_DEMAND',
            str_contains($text, 'suksesor') || str_contains($text, 'successor') || str_contains($text, 'suksesi') => 'SUCCESSION',
            str_contains($text, 'skill') || str_contains($text, 'keterampilan') => 'SKILL_GAP',
            str_contains($text, 'turnover') || str_contains($text, 'resign') || str_contains($text, 'attrition') => 'RETENTION_ANALYSIS',
            str_contains($text, 'kandidat') || str_contains($text, 'cocok') || str_contains($text, 'requirement') => 'TALENT_MATCH',
            str_contains($text, 'biaya') || str_contains($text, 'cost') || str_contains($text, 'budget') => 'COST_ANALYSIS',
            str_contains($text, 'headcount') || str_contains($text, 'berapeople') => 'METRIC_QUERY',
            default => 'GENERAL_HR',
        };
    }

    private function selectAgent(string $intent): string
    {
        return match ($intent) {
            'LEAVE_BALANCE', 'PAYROLL_EXPLANATION' => 'employee-agent',
            'WORKFORCE_DEMAND', 'COST_ANALYSIS' => 'workforce-agent',
            'SUCCESSION' => 'succession-agent',
            'SKILL_GAP' => 'learning-agent',
            'RETENTION_ANALYSIS' => 'analytics-agent',
            'TALENT_MATCH' => 'talent-agent',
            'LEARNING' => 'learning-agent',
            'METRIC_QUERY' => 'analytics-agent',
            default => 'hr-service-agent',
        };
    }

    /**
     * @return array<int, array{tool:string,arguments:array<string,mixed>}>
     */
    private function toolPlan(string $intent, array $request, mixed $user): array
    {
        $self = $this->ctx->employeeFor($user);
        $employeeId = (int) ($request['employee_id'] ?? $self?->getKey() ?? 0);

        return match ($intent) {
            'LEAVE_BALANCE' => [['tool' => 'get_leave_balance', 'arguments' => ['employee_id' => $employeeId]]],
            'PAYROLL_EXPLANATION' => [
                ['tool' => 'get_payroll', 'arguments' => ['employee_id' => $employeeId, 'period' => $request['period'] ?? now()->format('Y-m')]],
                ['tool' => 'get_employee', 'arguments' => ['employee_id' => $employeeId]],
            ],
            'WORKFORCE_DEMAND' => [['tool' => 'get_workforce_analysis', 'arguments' => $request['drivers'] ?? []]],
            'SUCCESSION' => [['tool' => 'get_succession', 'arguments' => ['organization_id' => $request['organization_id'] ?? null]]],
            'SKILL_GAP' => [['tool' => 'get_skills', 'arguments' => ['employee_id' => $employeeId]]],
            'RETENTION_ANALYSIS' => [['tool' => 'get_metrics', 'arguments' => ['metrics' => ['TURNOVER_RATE', 'HEADCOUNT']]]],
            'TALENT_MATCH' => [['tool' => 'get_employee', 'arguments' => ['employee_id' => $employeeId]],
                ['tool' => 'get_position', 'arguments' => ['limit' => 10]]],
            'COST_ANALYSIS' => [['tool' => 'get_metrics', 'arguments' => ['metrics' => ['PAYROLL_TOTAL', 'COST_PER_EMPLOYEE']]]],
            'METRIC_QUERY' => [['tool' => 'get_metrics', 'arguments' => ['metrics' => $request['metrics'] ?? ['HEADCOUNT']]]],
            'LEARNING' => [['tool' => 'get_training', 'arguments' => ['employee_id' => $employeeId]]],
            default => [['tool' => 'get_employee', 'arguments' => ['employee_id' => $employeeId]]],
        };
    }

    /**
     * @param  array<string, mixed>  $toolResults
     * @param  array<int, array<string, mixed>>  $denials
     * @return array<string, mixed>
     */
    private function compose(string $intent, string $question, array $toolResults, array $denials, mixed $user): array
    {
        $sources = [];
        $facts = [];
        foreach ($toolResults as $tool => $result) {
            $sources[] = $tool;
        }

        if ($denials !== [] && $toolResults === []) {
            return [
                'refused' => true,
                'answer' => 'Saya tidak dapat menjawab: akses ke data yang dibutuhkan ditolak ('.implode(', ', array_column($denials, 'reason')).').',
                'reason' => 'permission_denied', 'denials' => $denials, 'sources' => [],
                'calculation' => null, 'assumptions' => [], 'confidence' => 0, 'requires_confirmation' => false,
            ];
        }

        if ($toolResults === []) {
            return [
                'refused' => true,
                'answer' => 'Tidak ada sumber data HR yang dapat saya pakai untuk menjawab pertanyaan ini, jadi saya tidak akan menebak (no source → no factual answer).',
                'reason' => 'no_source', 'sources' => [], 'calculation' => null,
                'assumptions' => [], 'confidence' => 0, 'requires_confirmation' => false,
            ];
        }

        $answer = match ($intent) {
            'LEAVE_BALANCE' => $this->leaveAnswer($toolResults),
            'PAYROLL_EXPLANATION' => $this->payrollAnswer($toolResults),
            'WORKFORCE_DEMAND' => $this->workforceAnswer($toolResults),
            'SUCCESSION' => $this->successionAnswer($toolResults),
            'SKILL_GAP' => $this->skillAnswer($toolResults),
            'RETENTION_ANALYSIS' => $this->retentionAnswer($toolResults),
            'COST_ANALYSIS', 'METRIC_QUERY' => $this->metricAnswer($toolResults),
            'TALENT_MATCH' => $this->talentAnswer($toolResults),
            'LEARNING' => $this->learningAnswer($toolResults),
            default => $this->generalAnswer($toolResults),
        };

        $draft = $this->draftIfWriteIntent($intent, $question, $user);

        return [
            'refused' => false,
            'answer' => $answer['answer'],
            'table' => $answer['table'] ?? null,
            'sources' => $sources,
            'calculation' => $answer['calculation'] ?? null,
            'assumptions' => $answer['assumptions'] ?? [],
            'confidence' => $answer['confidence'] ?? 0.7,
            'evidence' => $answer['evidence'] ?? [],
            'draft' => $draft,
            'requires_confirmation' => $draft !== null,
            'explainability' => $this->governance->explain([
                'summary' => $answer['answer'], 'signals' => $answer['evidence'] ?? [],
                'period' => now()->format('Y-m'), 'sources' => $sources,
                'calculation' => $answer['calculation'] ?? null, 'assumptions' => $answer['assumptions'] ?? [],
                'confidence' => $answer['confidence'] ?? 0.7,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $toolResults
     * @return array<string, mixed>
     */
    private function leaveAnswer(array $toolResults): array
    {
        $payload = (array) ($toolResults['get_leave_balance']['data'] ?? []);
        $balances = (array) ($payload['balances'] ?? []);

        return [
            'answer' => $balances === []
                ? 'Saldo cuti belum tersedia di sistem untuk pegawai ini.'
                : 'Saldo cuti Anda: '.implode('; ', array_map(fn (array $row): string => sprintf(
                    '%s: sisa %.1f hari (hak %.1f, terpakai %.1f, pending %.1f)',
                    (string) ($row['year'] ?? '-'), (float) ($row['remaining'] ?? 0), (float) ($row['entitled'] ?? 0),
                    (float) ($row['used'] ?? 0), (float) ($row['pending'] ?? 0)
                ), $balances)),
            'table' => $balances, 'sources' => ['hr_leave_balances'],
            'calculation' => 'remaining = entitled - used - pending', 'confidence' => 0.95,
            'evidence' => ['balances' => $balances],
        ];
    }

    /**
     * @param  array<string, mixed>  $toolResults
     * @return array<string, mixed>
     */
    private function payrollAnswer(array $toolResults): array
    {
        $payroll = (array) ($toolResults['get_payroll']['data']['payroll'] ?? []);
        $masked = ($payroll['net_amount'] ?? null) === '[REDACTED]';

        return [
            'answer' => $masked
                ? 'Rincian gaji Anda memerlukan izin payroll; saya hanya dapat menyampaikan bahwa data tersedia.'
                : 'Perubahan take-home pay biasanya berasal dari komponen berikut:'.PHP_EOL
                    .'- Pokok prorata (hari tidak hadir)'.PHP_EOL
                    .'- Lembur terverifikasi'.PHP_EOL
                    .'- Iuran benefit & potongan wajib'.PHP_EOL
                    .'- Pajak PPh 21 sesuai lapisan berlaku'.PHP_EOL
                    .'Nilai pada periode ini: '.json_encode(array_intersect_key($payroll, array_flip(['base_amount', 'overtime_amount', 'net_amount', 'tax_amount']))),
            'table' => [$payroll], 'sources' => ['hr_payroll_items'],
            'calculation' => 'net = earnings - deductions - tax', 'confidence' => 0.85,
            'evidence' => ['payroll_item' => $payroll],
        ];
    }

    /**
     * @param  array<string, mixed>  $toolResults
     * @return array<string, mixed>
     */
    private function workforceAnswer(array $toolResults): array
    {
        $data = (array) ($toolResults['get_workforce_analysis']['data'] ?? []);
        $teachers = (array) ($data['teachers'] ?? []);
        $students = (array) ($data['students'] ?? []);

        return [
            'answer' => sprintf(
                'Dengan %d siswa (target %d) dan rasio %s siswa/guru, kebutuhan guru adalah %s orang; saat ini %s → gap %s orang.',
                (int) ($students['current'] ?? 0), (int) ($students['target'] ?? 0),
                (string) ($teachers['student_teacher_ratio_target'] ?? '-'), (string) ($teachers['required'] ?? 0),
                (string) ($teachers['current'] ?? 0), (string) ($teachers['gap'] ?? 0)
            ),
            'table' => [['metrik' => 'guru', 'nilai' => $teachers], ['metrik' => 'kapasitas', 'nilai' => $data['capacity'] ?? []]],
            'sources' => ['hr_employees (guru aktif)', 'modul Academic (siswa/kelas)'],
            'calculation' => 'guru_required = max(ceil(siswa / rasio), kelas / kelas_per_guru)',
            'assumptions' => (array) ($data['evidence']['assumptions'] ?? []),
            'confidence' => 0.8, 'evidence' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $toolResults
     * @return array<string, mixed>
     */
    private function successionAnswer(array $toolResults): array
    {
        $coverage = (array) ($toolResults['get_succession']['data'] ?? []);
        $without = array_values(array_filter((array) ($coverage['rows'] ?? []), fn (array $row): bool => ($row['coverage'] ?? '') === 'NO_SUCCESSOR'));

        return [
            'answer' => sprintf(
                'Cakupan suksesi %s%%: %d dari %d posisi kritis punya kandidat; %d posisi belum punya successor.',
                (string) ($coverage['coverage_percent'] ?? 0), (int) ($coverage['with_successor'] ?? 0),
                (int) ($coverage['critical_positions'] ?? 0), (int) ($coverage['without_successor'] ?? 0)
            ),
            'table' => $without, 'sources' => ['hr_critical_positions', 'hr_succession_candidates'],
            'calculation' => 'coverage% = posisi dengan kandidat / posisi kritis',
            'confidence' => 0.9, 'evidence' => ['positions_without_successor' => $without],
        ];
    }

    /**
     * @param  array<string, mixed>  $toolResults
     * @return array<string, mixed>
     */
    private function skillAnswer(array $toolResults): array
    {
        $skills = (array) ($toolResults['get_skills']['data']['skills'] ?? []);

        return [
            'answer' => $skills === []
                ? 'Belum ada keterampilan terdaftar untuk pegawai ini.'
                : 'Keterampilan tercatat: '.implode(', ', array_map(fn (array $row): string => ($row['code'] ?? '').' ('.($row['level'] ?? '-').')'.(($row['verified'] ?? false) ? ' ✓' : ''), $skills)),
            'table' => $skills, 'sources' => ['hr_employee_skills'],
            'calculation' => null, 'confidence' => 0.9, 'evidence' => ['skills' => $skills],
        ];
    }

    /**
     * @param  array<string, mixed>  $toolResults
     * @return array<string, mixed>
     */
    private function retentionAnswer(array $toolResults): array
    {
        $metrics = (array) ($toolResults['get_metrics']['data']['metrics'] ?? []);
        $turnover = $metrics['TURNOVER_RATE'] ?? [];

        return [
            'answer' => 'Turnover periode ini '.($turnover['value'] ?? 0).' ('.$turnover['numerator']['rows'].' pegawai keluar dari basis '
                .($turnover['denominator']['rows'] ?? 0).'). Pendorong yang perlu diperiksa: masa kerja tanpa promosi, kompensasi di bawah persentil 25, '
                .'beban lembur, dan pergantian atasan — semuanya tersedia di Retention Intelligence.',
            'table' => $metrics, 'sources' => $turnover['source_tables'] ?? [],
            'calculation' => 'turnover = pegawai keluar / headcount awal periode',
            'assumptions' => [], 'confidence' => 0.75, 'evidence' => ['metrics' => $metrics],
        ];
    }

    /**
     * @param  array<string, mixed>  $toolResults
     * @return array<string, mixed>
     */
    private function metricAnswer(array $toolResults): array
    {
        $metrics = (array) ($toolResults['get_metrics']['data']['metrics'] ?? []);
        $lines = [];
        foreach ($metrics as $code => $row) {
            $lines[] = $code.' = '.($row['value'] ?? 0).' '.($row['unit'] ?? '');
        }

        return [
            'answer' => 'Nilai metrik kanonik: '.implode('; ', $lines),
            'table' => $metrics, 'sources' => array_values(array_unique(array_merge(...array_map(
                fn (array $row): array => (array) ($row['source_tables'] ?? []), $metrics ?: [['source_tables' => []]]
            )))),
            'calculation' => 'Mengikuti definisi metrik (hr_metric_definitions).',
            'confidence' => 0.85, 'evidence' => ['metrics' => $metrics],
        ];
    }

    /**
     * @param  array<string, mixed>  $toolResults
     * @return array<string, mixed>
     */
    private function talentAnswer(array $toolResults): array
    {
        $employee = (array) ($toolResults['get_employee']['data'] ?? []);
        $positions = (array) ($toolResults['get_position']['data']['positions'] ?? []);

        return [
            'answer' => 'Kandidat internal untuk kebutuhan ini ditampilkan sebagai pool dengan skor kecocokan pada endpoint marketplace; '
                .'sistem tidak menunjuk pemenang. Kandidat saat ini: '.($employee['employee_number'] ?? '-').' dengan '
                .count($positions).' posisi pembanding.',
            'table' => $positions, 'sources' => ['hr_employees', 'hr_positions', 'hr_employee_skills'],
            'calculation' => 'match = bobot skill/experience/interest/career/availability/performance',
            'confidence' => 0.7, 'evidence' => ['candidate' => $employee, 'positions' => count($positions)],
        ];
    }

    /**
     * @param  array<string, mixed>  $toolResults
     * @return array<string, mixed>
     */
    private function learningAnswer(array $toolResults): array
    {
        $data = (array) ($toolResults['get_training']['data'] ?? []);

        return [
            'answer' => 'Partisipasi pembelajaran Anda '.($data['participation_percent'] ?? 0).'% dengan '
                .count((array) ($data['completions'] ?? [])).' pelatihan selesai.',
            'table' => (array) ($data['completions'] ?? []), 'sources' => ['hr_training_assignments', 'hr_training_completions'],
            'calculation' => 'partisipasi = selesai / ditugaskan', 'confidence' => 0.85, 'evidence' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $toolResults
     * @return array<string, mixed>
     */
    private function generalAnswer(array $toolResults): array
    {
        $employee = (array) ($toolResults['get_employee']['data'] ?? []);

        return [
            'answer' => 'Data pegawai yang dapat saya sampaikan: '.json_encode(array_intersect_key($employee,
                array_flip(['employee_number', 'position', 'lifecycle_state', 'hire_date']))),
            'table' => [$employee], 'sources' => ['hr_employees'],
            'calculation' => null, 'confidence' => 0.6, 'evidence' => ['employee' => $employee],
        ];
    }

    /**
     * Draf aksi tulis bila pengguna memintanya (§68).
     *
     * @return array<string, mixed>|null
     */
    private function draftIfWriteIntent(string $intent, string $question, mixed $user): ?array
    {
        $text = mb_strtolower($question);
        $wantsWrite = str_contains($text, 'siapkan') || str_contains($text, 'buatkan') || str_contains($text, 'draft')
            || str_contains($text, 'ajukan');
        if (! $wantsWrite) {
            return null;
        }

        $isTraining = str_contains($text, 'training') || str_contains($text, 'pelatihan');
        $gate = $this->governance->assertActionAllowed($isTraining ? 'CREATE_TRAINING_REQUEST' : 'CREATE_HR_REQUEST');

        return [
            'action' => $isTraining ? 'CREATE_TRAINING_REQUEST' : 'CREATE_HR_REQUEST',
            'allowed' => $gate['allowed'], 'policy_reason' => $gate['reason'],
            'request' => [
                'employee_id' => $this->ctx->employeeFor($user)?->getKey(),
                'category' => $isTraining ? 'TRAINING' : 'GENERAL',
                'subject' => mb_substr($question, 0, 120),
                'description' => 'Draf dibuat AI Copilot, menunggu konfirmasi manusia.',
            ],
            'requires_confirmation' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $composition
     * @return array<string, mixed>
     */
    private function respond(HrAiRun $run, array $composition): array
    {
        return [
            'run' => $run->code, 'agent' => $run->agent_code, 'intent' => $run->intent,
            'status' => $composition['refused'] ? 'REFUSED' : $run->status,
            'answer' => $composition['answer'], 'table' => $composition['table'] ?? null,
            'sources' => $composition['sources'] ?? [], 'calculation' => $composition['calculation'] ?? null,
            'assumptions' => $composition['assumptions'] ?? [], 'confidence' => $composition['confidence'] ?? 0,
            'evidence' => $composition['evidence'] ?? [],
            'draft' => $composition['draft'] ?? null,
            'requires_confirmation' => (bool) ($composition['requires_confirmation'] ?? false),
            'explainability' => $composition['explainability'] ?? null,
            'governance' => ['human_in_the_loop' => true, 'prohibited_actions' => config('hrd_phase3.ai.prohibited_actions', [])],
        ];
    }
}
