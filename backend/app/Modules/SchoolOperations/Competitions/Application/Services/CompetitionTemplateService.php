<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Competitions\Models\Competition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionEdition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRound;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRule;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRuleVersion;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Creates a new edition from a versioned template without copying historical operations. */
class CompetitionTemplateService
{
    public function __construct(
        private readonly CompetitionCatalogService $catalog,
        private readonly CompetitionScopeService $scope,
        private readonly CompetitionAuditService $audit,
    ) {}

    public function list(array $filters = [])
    {
        return CompetitionTemplate::query()->where('is_active', true)
            ->when(! empty($filters['type_id']), fn ($query) => $query->where('competition_type_id', $filters['type_id']))
            ->when(! empty($filters['domain_id']), fn ($query) => $query->where('competition_domain_id', $filters['domain_id']))
            ->orderBy('name')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 50))));
    }

    public function instantiate(Competition $competition, int $templateId, array $editionData, User $actor): CompetitionEdition
    {
        $this->scope->assertCompetition($actor, $competition);
        $template = CompetitionTemplate::query()->where('is_active', true)->with([
            'competitionTemplateCategorys', 'competitionTemplateRules', 'competitionTemplateRounds',
        ])->findOrFail($templateId);
        if ($template->competition_type_id !== null && (int) $template->competition_type_id !== (int) $competition->competition_type_id) {
            throw ValidationException::withMessages(['template_id' => 'Template menggunakan jenis kompetisi yang berbeda.']);
        }

        return DB::transaction(function () use ($competition, $template, $editionData, $actor): CompetitionEdition {
            $edition = $this->catalog->createEdition($competition, [
                ...$editionData,
                'participant_type' => $editionData['participant_type'] ?? $template->default_participant_mode,
                'requires_eligibility' => $editionData['requires_eligibility'] ?? $template->requires_eligibility,
                'requires_parent_consent' => $editionData['requires_parent_consent'] ?? $template->requires_parent_consent,
                'requires_selection' => $editionData['requires_selection'] ?? $template->requires_selection,
                'settings' => array_merge((array) $template->settings, (array) ($editionData['settings'] ?? []), ['template_id' => $template->id, 'template_code' => $template->code]),
            ], $actor);

            $categoryMap = [];
            foreach ($template->competitionTemplateCategorys as $templateCategory) {
                $category = $this->catalog->createCategory($edition, [
                    'code' => $templateCategory->code,
                    'name' => $templateCategory->name,
                    'description' => $templateCategory->description,
                    'competition_division_id' => $templateCategory->competition_division_id,
                    'participant_mode' => $templateCategory->participant_mode ?: $template->default_participant_mode,
                    'min_team_size' => $templateCategory->min_team_size,
                    'max_team_size' => $templateCategory->max_team_size,
                    'capacity' => $templateCategory->capacity,
                    'scoring_model' => $templateCategory->scoring_model ?: $template->default_scoring_model,
                    'ranking_model' => $templateCategory->ranking_model ?: $template->default_ranking_model,
                    'settings' => $templateCategory->settings,
                ], $actor);
                $categoryMap[$templateCategory->code] = $category->id;
            }

            foreach ($template->competitionTemplateRules as $index => $templateRule) {
                $categoryId = $templateRule->category_code ? ($categoryMap[$templateRule->category_code] ?? null) : null;
                $rule = CompetitionRule::query()->create([
                    'edition_id' => $edition->id,
                    'category_id' => $categoryId,
                    'code' => strtoupper((string) ($templateRule->code ?: 'RULE-'.$templateRule->id)),
                    'name' => $templateRule->name,
                    'rules_type' => $templateRule->rules_type,
                    'description' => $templateRule->content,
                    'is_mandatory' => $templateRule->is_mandatory,
                    'sort_order' => $templateRule->sort_order ?: $index,
                    'status' => 'PUBLISHED',
                ]);
                CompetitionRuleVersion::query()->create([
                    'rule_id' => $rule->id,
                    'version' => 1,
                    'content' => $templateRule->content,
                    'structured_content' => ['template_id' => $template->id, 'category_code' => $templateRule->category_code],
                    'change_summary' => 'Initial copy from template '.$template->code,
                    'status' => 'PUBLISHED',
                    'effective_from' => now()->toDateString(),
                    'published_at' => now(),
                    'created_by' => $actor->id,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                ]);
            }

            foreach ($template->competitionTemplateRounds as $templateRound) {
                $targetCategoryIds = $templateRound->category_code
                    ? array_filter([$categoryMap[$templateRound->category_code] ?? null])
                    : array_values($categoryMap);
                foreach ($targetCategoryIds as $categoryId) {
                    CompetitionRound::query()->create([
                        'edition_id' => $edition->id,
                        'category_id' => $categoryId,
                        'code' => strtoupper(($templateRound->code ?: 'ROUND-'.$templateRound->sequence).'-'.$categoryId),
                        'name' => $templateRound->name,
                        'sequence' => $templateRound->sequence,
                        'round_type' => $templateRound->round_type,
                        'format' => $templateRound->format,
                        'advancement_rule' => $templateRound->advancement_rule,
                        'qualification_count' => $templateRound->qualification_count,
                        'status' => 'DRAFT',
                        'settings' => $templateRound->settings,
                        'created_by' => $actor->id,
                    ]);
                }
            }

            $template->increment('usage_count');
            $this->audit->record('CompetitionEditionCreatedFromTemplate', $actor, $edition, after: ['template_id' => $template->id, 'template_code' => $template->code, 'categories' => count($categoryMap)]);

            return $edition->refresh();
        });
    }
}
