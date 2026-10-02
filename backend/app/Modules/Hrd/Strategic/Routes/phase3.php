<?php

declare(strict_types=1);

use App\Modules\Hrd\Strategic\Http\Controllers\AiController;
use App\Modules\Hrd\Strategic\Http\Controllers\AnalyticsController;
use App\Modules\Hrd\Strategic\Http\Controllers\GovernanceController;
use App\Modules\Hrd\Strategic\Http\Controllers\Phase3MetaController;
use App\Modules\Hrd\Strategic\Http\Controllers\SkillsController;
use App\Modules\Hrd\Strategic\Http\Controllers\StrategicController;
use App\Modules\Hrd\Strategic\Http\Controllers\SuccessionController;
use App\Modules\Hrd\Strategic\Http\Controllers\TalentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HRD Phase 3 — API strategis (/api/v1/hr/*)
|--------------------------------------------------------------------------
*/

Route::get('meta', Phase3MetaController::class)->name('hrd.phase3.meta');

/* -------------------------------------------------------------------------
 | Strategic workforce planning (permintaan, pasokan, gap, biaya, skenario)
 | ------------------------------------------------------------------------ */
Route::prefix('strategic')->group(function (): void {
    Route::middleware('permission:hr.strategic.view')->group(function (): void {
        Route::get('workforce', [StrategicController::class, 'workforce'])->name('hrd.strategic.workforce');
        Route::get('workforce/gaps', [StrategicController::class, 'gaps'])->name('hrd.strategic.gaps');
        Route::get('workforce/cost', [StrategicController::class, 'cost'])->name('hrd.strategic.cost');
        Route::get('workforce/positions', [StrategicController::class, 'positions'])->name('hrd.strategic.positions');
        Route::get('workforce/capacity', [StrategicController::class, 'capacity'])->name('hrd.strategic.capacity');
        Route::get('workforce/scenarios/compare', [StrategicController::class, 'compareScenarios'])->name('hrd.strategic.scenarios.compare');
        Route::get('workforce/span-of-control', [StrategicController::class, 'spanOfControl'])->name('hrd.strategic.span');
        Route::get('workforce/network', [StrategicController::class, 'network'])->name('hrd.strategic.network');
        Route::get('risks', [GovernanceController::class, 'risks'])->name('hrd.strategic.risks');
        Route::get('actions', [GovernanceController::class, 'actions'])->name('hrd.strategic.actions');
        Route::get('command-center', [GovernanceController::class, 'commandCenter'])->name('hrd.strategic.command_center');
        Route::get('scorecard', [GovernanceController::class, 'scorecard'])->name('hrd.strategic.scorecard');
        Route::get('executive', [GovernanceController::class, 'executive'])->name('hrd.strategic.executive');
        Route::get('foundation', [GovernanceController::class, 'foundation'])->name('hrd.strategic.foundation');
        Route::get('analytics-board', [GovernanceController::class, 'analyticsBoard'])->name('hrd.strategic.analytics_board');
    });

    Route::middleware('permission:hr.strategic.manage')->group(function (): void {
        Route::post('workforce/plans', [StrategicController::class, 'plans'])->name('hrd.strategic.plans.store');
        Route::post('workforce/demand', [StrategicController::class, 'demand'])->name('hrd.strategic.demand.store');
        Route::post('workforce/supply', [StrategicController::class, 'supply'])->name('hrd.strategic.supply.store');
        Route::post('workforce/gaps', [StrategicController::class, 'gaps'])->name('hrd.strategic.gaps.analyse');
        Route::post('workforce/cost', [StrategicController::class, 'cost'])->name('hrd.strategic.cost.forecast');
        Route::post('workforce/scenarios', [StrategicController::class, 'scenarios'])->name('hrd.strategic.scenarios.store');
        Route::post('workforce/scenarios/{scenario}/simulate', [StrategicController::class, 'simulateScenario'])->name('hrd.strategic.scenarios.simulate');
        Route::post('workforce/what-if', [StrategicController::class, 'whatIf'])->name('hrd.strategic.what_if');
        Route::post('workforce/organization-design', [StrategicController::class, 'organizationDesign'])->name('hrd.strategic.organization_design.store');
        Route::post('workforce/organization-design/{scenario}/simulate', [StrategicController::class, 'simulateOrganizationDesign'])->name('hrd.strategic.organization_design.simulate');
        Route::post('risks/scan', [GovernanceController::class, 'scan'])->name('hrd.strategic.risks.scan');
        Route::post('actions', [GovernanceController::class, 'actions'])->name('hrd.strategic.actions.store');
        Route::post('actions/{action}/decide', [GovernanceController::class, 'decideAction'])->name('hrd.strategic.actions.decide');
        Route::post('actions/{action}/measure', [GovernanceController::class, 'measureAction'])->name('hrd.strategic.actions.measure');
        Route::post('actions/{action}/review', [GovernanceController::class, 'reviewAction'])->name('hrd.strategic.actions.review');
    });
});

/* -------------------------------------------------------------------------
 | Skills intelligence
 | ------------------------------------------------------------------------ */
Route::prefix('skills')->group(function (): void {
    Route::middleware('permission:hr.skills.view')->group(function (): void {
        Route::get('intelligence', [SkillsController::class, 'intelligence'])->name('hrd.skills.intelligence');
        Route::get('gaps', [SkillsController::class, 'gaps'])->name('hrd.skills.gaps');
        Route::get('demand', [SkillsController::class, 'demand'])->name('hrd.skills.demand');
        Route::get('forecast', [SkillsController::class, 'forecast'])->name('hrd.skills.forecast');
        Route::get('heatmap', [SkillsController::class, 'heatmap'])->name('hrd.skills.heatmap');
        Route::get('critical', [SkillsController::class, 'critical'])->name('hrd.skills.critical');
        Route::get('governance', [SkillsController::class, 'governance'])->name('hrd.skills.governance');
    });
    Route::middleware('permission:hr.skills.manage')->group(function (): void {
        Route::post('evidence', [SkillsController::class, 'evidence'])->name('hrd.skills.evidence.store');
        Route::post('assess', [SkillsController::class, 'assess'])->name('hrd.skills.assess');
        Route::post('build-buy-borrow', [SkillsController::class, 'buildBuyBorrow'])->name('hrd.skills.build_buy_borrow');
        Route::post('sync-requirements', [SkillsController::class, 'syncRequirements'])->name('hrd.skills.sync');
    });
});

/* -------------------------------------------------------------------------
 | Talent intelligence, marketplace, career
 | ------------------------------------------------------------------------ */
Route::prefix('talent')->group(function (): void {
    Route::middleware('permission:hr.talent.view')->group(function (): void {
        Route::get('intelligence/{employee}', [TalentController::class, 'intelligence'])->name('hrd.talent.intelligence');
        Route::get('segments', [TalentController::class, 'segments'])->name('hrd.talent.segments');
        Route::get('matrix', [TalentController::class, 'matrix'])->name('hrd.talent.matrix');
        Route::get('mobility', [TalentController::class, 'mobility'])->name('hrd.talent.mobility');
        Route::get('pools', [TalentController::class, 'marketplace'])->name('hrd.talent.pools');
        Route::get('marketplace', [TalentController::class, 'marketplace'])->name('hrd.talent.marketplace');
        Route::get('marketplace/allocation', [TalentController::class, 'allocation'])->name('hrd.talent.allocation');
        Route::get('marketplace/candidates', [TalentController::class, 'candidates'])->name('hrd.talent.candidates');
        Route::get('career/paths', [TalentController::class, 'careerPaths'])->name('hrd.talent.career.paths');
        Route::get('career/simulate', [TalentController::class, 'careerSimulate'])->name('hrd.talent.career.simulate');
        Route::get('career/mobility', [TalentController::class, 'internalMobility'])->name('hrd.talent.career.mobility');
    });
    Route::middleware('permission:hr.marketplace.manage')->group(function (): void {
        Route::post('marketplace', [TalentController::class, 'publish'])->name('hrd.talent.marketplace.store');
        Route::post('marketplace/{opportunity}/match', [TalentController::class, 'match'])->name('hrd.talent.marketplace.match');
        Route::post('marketplace/matches/{match}/review', [TalentController::class, 'reviewMatch'])->name('hrd.talent.marketplace.review');
        Route::post('marketplace/projects', [TalentController::class, 'assignProject'])->name('hrd.talent.marketplace.projects.store');
        Route::post('marketplace/temporary', [TalentController::class, 'assignTemporary'])->name('hrd.talent.marketplace.temporary.store');
        Route::post('career/plan', [TalentController::class, 'careerPlan'])->name('hrd.talent.career.plan');
        Route::get('marketplace/{employee}/matches', [TalentController::class, 'matchForEmployee'])->name('hrd.talent.marketplace.matches');
    });
});

/* -------------------------------------------------------------------------
 | Succession
 | ------------------------------------------------------------------------ */
Route::prefix('succession')->group(function (): void {
    Route::middleware('permission:hr.succession.view')->group(function (): void {
        Route::get('/', [SuccessionController::class, 'index'])->name('hrd.succession.index');
        Route::get('coverage', [SuccessionController::class, 'coverage'])->name('hrd.succession.coverage');
        Route::get('pipeline', [SuccessionController::class, 'pipeline'])->name('hrd.succession.pipeline');
    });
    Route::middleware('permission:hr.succession.manage')->group(function (): void {
        Route::post('critical-positions', [SuccessionController::class, 'flagCritical'])->name('hrd.succession.critical.store');
        Route::post('critical-positions/{critical}/plans', [SuccessionController::class, 'createPlan'])->name('hrd.succession.plans.store');
        Route::post('critical-positions/{critical}/scenario', [SuccessionController::class, 'scenario'])->name('hrd.succession.scenario');
        Route::post('plans/{plan}/candidates', [SuccessionController::class, 'nominate'])->name('hrd.succession.candidates.store');
        Route::post('plans/{plan}/risks', [SuccessionController::class, 'risks'])->name('hrd.succession.risks.assess');
        Route::post('pipeline', [SuccessionController::class, 'addToPipeline'])->name('hrd.succession.pipeline.store');
    });
});

/* -------------------------------------------------------------------------
 | Analytics, metrics, forecast, compensation, retention, experience
 | ------------------------------------------------------------------------ */
Route::prefix('analytics')->group(function (): void {
    Route::middleware('permission:hr.analytics.view')->group(function (): void {
        Route::get('/', [AnalyticsController::class, 'index'])->name('hrd.analytics.index');
        Route::get('coverage', [AnalyticsController::class, 'coverage'])->name('hrd.analytics.coverage');
        Route::get('catalog', [AnalyticsController::class, 'catalog'])->name('hrd.analytics.catalog');
        Route::get('snapshots', [AnalyticsController::class, 'snapshots'])->name('hrd.analytics.snapshots');
        Route::get('forecast', [AnalyticsController::class, 'forecast'])->name('hrd.analytics.forecast');
        Route::get('retention', [AnalyticsController::class, 'retention'])->name('hrd.analytics.retention');
        Route::get('attrition', [AnalyticsController::class, 'attrition'])->name('hrd.analytics.attrition');
        Route::get('experience', [AnalyticsController::class, 'experience'])->name('hrd.analytics.experience');
        Route::get('journey', [AnalyticsController::class, 'journey'])->name('hrd.analytics.journey');
        Route::get('services', [AnalyticsController::class, 'services'])->name('hrd.analytics.services');
        Route::get('compensation', [AnalyticsController::class, 'compensation'])->name('hrd.analytics.compensation');
    });
    Route::middleware('permission:hr.analytics.manage')->group(function (): void {
        Route::post('snapshots', [AnalyticsController::class, 'snapshots'])->name('hrd.analytics.snapshots.store');
        Route::post('quality-check', [AnalyticsController::class, 'qualityCheck'])->name('hrd.analytics.quality');
        Route::post('forecast/run', [AnalyticsController::class, 'runForecast'])->name('hrd.analytics.forecast.run');
        Route::post('compensation/pay-equity', [AnalyticsController::class, 'payEquity'])->name('hrd.analytics.pay_equity');
        Route::post('compensation/simulate', [AnalyticsController::class, 'simulateCompensation'])->name('hrd.analytics.compensation.simulate');
        Route::post('compensation/incentive', [AnalyticsController::class, 'simulateIncentive'])->name('hrd.analytics.incentive.simulate');
        Route::post('retention/signals', [AnalyticsController::class, 'retentionSignals'])->name('hrd.analytics.retention.signals');
    });
});

Route::prefix('metrics')->middleware('permission:hr.metric.view')->group(function (): void {
    Route::get('/', [AnalyticsController::class, 'metrics'])->name('hrd.metrics.index');
    Route::get('{metric}', [AnalyticsController::class, 'metric'])->name('hrd.metrics.show');
});

/* -------------------------------------------------------------------------
 | AI: copilot, agent, rekomendasi, governance
 | ------------------------------------------------------------------------ */
Route::prefix('ai')->group(function (): void {
    Route::middleware('permission:hr.ai.use')->group(function (): void {
        Route::post('ask', [AiController::class, 'ask'])->name('hrd.ai.ask');
        Route::get('agents', [AiController::class, 'agents'])->name('hrd.ai.agents');
        Route::get('runs', [AiController::class, 'runs'])->name('hrd.ai.runs');
        Route::get('recommendations', [AiController::class, 'recommendations'])->name('hrd.ai.recommendations');
        Route::get('tools', [AiController::class, 'tools'])->name('hrd.ai.tools');
        Route::get('policy', [AiController::class, 'policy'])->name('hrd.ai.policy');
        Route::get('semantic', [AiController::class, 'semantic'])->name('hrd.ai.semantic');
        Route::post('query', [AiController::class, 'query'])->name('hrd.ai.query');
        Route::post('knowledge/ask', [AiController::class, 'knowledge'])->name('hrd.ai.knowledge.ask');
        Route::post('runs/{run}/feedback', [AiController::class, 'feedback'])->name('hrd.ai.feedback');
        Route::post('runs/{run}/confirm', [AiController::class, 'confirmWrite'])->name('hrd.ai.confirm');
    });
    Route::middleware('permission:hr.ai.manage')->group(function (): void {
        Route::post('recommendations', [AiController::class, 'recommendations'])->name('hrd.ai.recommendations.store');
        Route::post('recommendations/{recommendation}/review', [AiController::class, 'reviewRecommendation'])->name('hrd.ai.recommendations.review');
        Route::post('recommendations/{recommendation}/convert', [AiController::class, 'convertRecommendation'])->name('hrd.ai.recommendations.convert');
        Route::get('registry', [AiController::class, 'registry'])->name('hrd.ai.registry');
        Route::get('cost', [AiController::class, 'cost'])->name('hrd.ai.cost');
        Route::get('bias', [AiController::class, 'bias'])->name('hrd.ai.bias');
        Route::post('knowledge/index', [AiController::class, 'indexKnowledge'])->name('hrd.ai.knowledge.index');
    });
});
