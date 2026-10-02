<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Endpoint discoverability: apa saja yang tersedia di lapisan strategis, dari
 * domain, kontrak, endpoint, agent AI, sampai kondisi data pendukungnya.
 * Berguna untuk health-check Phase 3 tanpa harus menebak-nebak.
 */
class Phase3MetaController
{
    public function __invoke(Request $request): JsonResponse
    {
        $tables = [
            'hr_workforce_plans', 'hr_workforce_demand', 'hr_workforce_gaps', 'hr_workforce_scenarios',
            'hr_skill_evidence', 'hr_skill_gaps', 'hr_skill_forecasts', 'hr_critical_positions',
            'hr_succession_plans', 'hr_succession_candidates', 'hr_talent_opportunities',
            'hr_metric_definitions', 'hr_workforce_snapshots', 'hr_forecast_runs', 'hr_ai_runs',
            'hr_ai_tool_calls', 'hr_ai_recommendations', 'hr_risks', 'hr_strategic_hr_actions',
            'hr_retention_signals', 'hr_semantic_entities',
        ];

        $tableStatus = [];
        foreach ($tables as $table) {
            try {
                $tableStatus[$table] = Schema::hasTable($table) ? (int) DB::table($table)->count() : null;
            } catch (Throwable) {
                $tableStatus[$table] = null;
            }
        }

        return response()->json([
            'module' => 'hrd-phase3',
            'name' => 'Strategic HCM / Workforce Intelligence',
            'version' => '3.0.0',
            'enabled' => (bool) config('hrd_phase3.enabled'),
            'principles' => [
                'evidence_based', 'permission_aware', 'explainable', 'auditable', 'human_in_the_loop',
            ],
            'domains' => [
                'Workforce Planning', 'Scenario & What-If', 'Organization Design', 'Skills Intelligence',
                'Skill Gap & Demand', 'Talent Intelligence', 'Talent Marketplace', 'Career Intelligence',
                'Succession Planning', 'People Analytics', 'Forecasting', 'Compensation Intelligence',
                'Retention Intelligence', 'Employee Experience', 'HR Risk Register', 'Strategic Action Plan',
                'AI HR Assistant & Agents', 'Semantic Query Layer', 'Knowledge Assistant',
            ],
            'panels' => [
                'strategic_dashboard', 'workforce_intelligence', 'skills_intelligence', 'talent_intelligence',
                'succession', 'career_intelligence', 'compensation_intelligence', 'people_analytics',
                'forecasting', 'ai', 'risk_center', 'command_center',
            ],
            'contracts' => array_keys((array) config('hrd_phase3.bindings', [])),
            'data_sources' => [
                'phase1' => ['employees', 'positions', 'organizations', 'assignments', 'skills', 'contracts'],
                'phase2' => ['recruitment', 'attendance', 'leave', 'payroll', 'benefits', 'performance', 'learning', 'career', 'talent'],
                'external' => ['academic', 'boarding', 'clinic', 'finance', 'asset', 'survey', 'helpdesk'],
            ],
            'ai' => [
                'enabled' => (bool) config('hrd_phase3.ai.enabled'),
                'no_source_no_answer' => (bool) config('hrd_phase3.ai.no_source_no_answer'),
                'human_review_for_writes' => (bool) config('hrd_phase3.ai.require_human_review_for_writes'),
                'prohibited_actions' => (array) config('hrd_phase3.ai.prohibited_actions', []),
                'routing' => (array) config('hrd_phase3.ai.routing', []),
            ],
            'stats' => [
                'tables' => $tableStatus,
                'privacy_threshold' => (int) config('hrd_phase3.analytics.privacy_threshold'),
            ],
            'viewer' => [
                'user_id' => $request->user()?->getAuthIdentifier(),
                'scope' => $request->user() ? 'AUTHENTICATED' : 'GUEST',
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
