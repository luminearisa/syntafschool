<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Http\Controllers;

use App\Modules\Hrd\Strategic\Ai\Models\HrAiRecommendation;
use App\Modules\Hrd\Strategic\Ai\Models\HrAiRun;
use App\Modules\Hrd\Strategic\Ai\Services\AIGovernanceService;
use App\Modules\Hrd\Strategic\Ai\Services\AIOrchestratorService;
use App\Modules\Hrd\Strategic\Ai\Services\AiIntelligenceService;
use App\Modules\Hrd\Strategic\Ai\Services\AIToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController
{
    public function __construct(
        private readonly AIOrchestratorService $orchestrator,
        private readonly AIToolRegistry $tools,
        private readonly AIGovernanceService $governance,
        private readonly AiIntelligenceService $intelligence,
    ) {}

    public function ask(Request $request): JsonResponse
    {
        return response()->json($this->orchestrator->ask($request->all(), $request->user(), (string) $request->input('channel', 'COPILOT')));
    }

    public function agents(): JsonResponse
    {
        return response()->json($this->orchestrator->agents());
    }

    public function runs(Request $request): JsonResponse
    {
        return response()->json($this->orchestrator->runs((int) $request->input('limit', 20)));
    }

    public function recommendations(Request $request): JsonResponse
    {
        if ($request->isMethod('post')) {
            return response()->json($this->orchestrator->recommend($request->all(), $request->user()));
        }

        return response()->json(HrAiRecommendation::query()->orderByDesc('id')->limit((int) $request->input('limit', 50))->get());
    }

    public function reviewRecommendation(Request $request, HrAiRecommendation $recommendation): JsonResponse
    {
        return response()->json($this->orchestrator->reviewRecommendation((int) $recommendation->getKey(),
            (string) $request->input('decision', 'APPROVED'), $request->user(), $request->input('notes')));
    }

    public function convertRecommendation(Request $request, HrAiRecommendation $recommendation): JsonResponse
    {
        return response()->json($this->orchestrator->convertToAction((int) $recommendation->getKey(), $request->user()));
    }

    public function confirmWrite(Request $request, HrAiRun $run): JsonResponse
    {
        return response()->json($this->orchestrator->confirmWriteAction((int) $run->getKey(), $request->user()));
    }

    public function tools(): JsonResponse
    {
        return response()->json($this->tools->tools());
    }

    public function registry(): JsonResponse
    {
        return response()->json($this->governance->registry());
    }

    public function cost(): JsonResponse
    {
        return response()->json($this->governance->costDashboard());
    }

    public function bias(Request $request): JsonResponse
    {
        return response()->json($this->governance->monitorBias((string) $request->input('period', now()->format('Y-m')),
            (string) $request->input('context', 'RECRUITMENT')));
    }

    public function feedback(Request $request, HrAiRun $run): JsonResponse
    {
        return response()->json($this->governance->feedback((int) $run->getKey(), (string) $request->input('feedback', 'HELPFUL'),
            $request->user(), $request->input('comment')));
    }

    public function semantic(): JsonResponse
    {
        return response()->json($this->intelligence->semanticEntities());
    }

    public function query(Request $request): JsonResponse
    {
        return response()->json($this->intelligence->query((string) $request->input('question', ''), $request->user(),
            (array) $request->input('filters', [])));
    }

    public function knowledge(Request $request): JsonResponse
    {
        return response()->json($this->intelligence->askKnowledge((string) $request->input('question', ''), $request->user()));
    }

    public function indexKnowledge(Request $request): JsonResponse
    {
        return response()->json($this->intelligence->indexDocument($request->all(), $request->user()));
    }

    public function policy(): JsonResponse
    {
        return response()->json([
            'prohibited_actions' => config('hrd_phase3.ai.prohibited_actions', []),
            'no_source_no_answer' => (bool) config('hrd_phase3.ai.no_source_no_answer'),
            'human_review_for_writes' => (bool) config('hrd_phase3.ai.require_human_review_for_writes'),
            'blocked_fields' => config('hrd_phase3.ai.blocked_fields', []),
        ]);
    }
}
