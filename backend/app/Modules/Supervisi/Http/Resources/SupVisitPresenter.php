<?php

namespace App\Modules\Supervisi\Http\Resources;

use App\Models\User;
use App\Modules\Supervisi\Application\Services\ScoringEngine;
use App\Modules\Supervisi\Application\Services\VisitAccessService;
use App\Modules\Supervisi\Application\Services\VisitAnswerService;
use App\Modules\Supervisi\Application\Services\VisitFinalizationService;
use App\Modules\Supervisi\Models\SupVisit;

/**
 * Penyusun bentuk data kegiatan supervisi untuk API.
 *
 * Dipisah dari controller agar daftar, detail, dan respons setelah tindakan
 * selalu punya bentuk yang sama — frontend cukup mengenal satu skema.
 */
class SupVisitPresenter
{
    public function __construct(
        private readonly VisitAccessService $access,
        private readonly VisitAnswerService $answers,
        private readonly VisitFinalizationService $finalization,
        private readonly ScoringEngine $engine,
    ) {}

    /**
     * Baris daftar (ringkas) untuk halaman "Kegiatan Supervisi".
     *
     * @return array<string,mixed>
     */
    public function list(SupVisit $visit): array
    {
        return [
            'id' => (int) $visit->id,
            'code' => $visit->code,
            'activity_kind' => $visit->activity_kind->value,
            'activity_kind_label' => $visit->activity_kind->label(),
            'approach' => $visit->approach->value,
            'approach_label' => $visit->approach->label(),
            'status' => $visit->status->value,
            'status_label' => $visit->status->label(),
            'is_finalized' => $visit->isFinalized(),
            'is_disputed' => (bool) $visit->is_disputed,
            'supervisor' => $this->employee($visit->supervisor),
            'supervisee' => $this->employee($visit->supervisee),
            'instrument' => $visit->instrumentVersion?->instrument === null ? null : [
                'id' => (int) $visit->instrumentVersion->instrument->id,
                'name' => $visit->instrumentVersion->instrument->name,
                'version' => (int) $visit->instrumentVersion->version,
            ],
            'lesson_topic' => $visit->lesson_topic,
            'observed_at' => $visit->observed_at?->toIso8601String(),
            'duration_minutes' => $visit->duration_minutes,
            'final_score' => $visit->final_score,
            'max_score' => $visit->max_score,
            'grade_band' => $visit->grade_band,
            'grade_label' => $visit->grade_label,
            'grade_tone' => $visit->grade_tone,
            'answers_count' => (int) ($visit->answers_count ?? 0),
            'findings_count' => (int) ($visit->findings_count ?? 0),
            'needs_acknowledgment' => $visit->isFinalized() && ! $visit->is_disputed
                && $visit->acknowledgments->contains(fn ($ack) => $ack->acknowledged_at === null),
            'finalized_at' => $visit->finalized_at?->toIso8601String(),
        ];
    }

    /**
     * Detail lengkap untuk halaman pelaksanaan & halaman hasil.
     *
     * @return array<string,mixed>
     */
    public function detail(SupVisit $visit, User $viewer): array
    {
        $version = $visit->instrumentVersion;
        $version?->loadMissing(['instrument', 'gradeScale', 'sections.indicators.options']);

        $preview = $this->answers->preview($visit->load('answers'), $version);
        $canExecute = $this->access->canExecute($viewer, $visit);
        $canFinalize = $this->access->canFinalize($viewer, $visit) && ! $visit->isFinalized();
        $canViewAll = $this->access->isGovernance($viewer);

        $answersByIndicator = $visit->answers->keyBy('indicator_id');

        $sections = $version === null ? [] : $version->sections->map(fn ($section) => [
            'id' => (int) $section->id,
            'code' => $section->code,
            'title' => $section->title,
            'description' => $section->description,
            'weight' => (float) $section->weight,
            'sort_order' => (int) $section->sort_order,
            'indicators' => $section->indicators->map(fn ($indicator) => [
                'id' => (int) $indicator->id,
                'code' => $indicator->code,
                'statement' => $indicator->statement,
                'guidance' => $indicator->guidance,
                'evidence_hint' => $indicator->evidence_hint,
                'input_type' => $indicator->input_type->value,
                'is_scored' => (bool) $indicator->is_scored,
                'is_required' => (bool) $indicator->is_required,
                'allow_na' => (bool) $indicator->allow_na,
                'is_critical' => (bool) $indicator->is_critical,
                'weight' => (float) $indicator->weight,
                'min_comment_below' => $indicator->min_comment_below === null ? null : (float) $indicator->min_comment_below,
                'score_min' => $indicator->score_min === null ? null : (float) $indicator->score_min,
                'score_max' => $indicator->score_max === null ? null : (float) $indicator->score_max,
                'score_step' => $indicator->score_step === null ? null : (float) $indicator->score_step,
                'score_unit' => $indicator->score_unit,
                'score_yes' => $indicator->score_yes === null ? null : (float) $indicator->score_yes,
                'score_no' => $indicator->score_no === null ? null : (float) $indicator->score_no,
                'score_partial' => $indicator->score_partial === null ? null : (float) $indicator->score_partial,
                'direction' => $indicator->direction->value,
                'max_score' => $this->engine->indicatorMaxScore($indicator),
                'options' => $indicator->options->map(fn ($option) => [
                    'id' => (int) $option->id,
                    'label' => $option->label,
                    'descriptor' => $option->descriptor,
                    'score' => (float) $option->score,
                    'tone' => $option->tone->value,
                    'is_default' => (bool) $option->is_default,
                ])->all(),
            ])->all(),
        ])->all();

        $computed = collect($preview['indicator_scores'] ?? [])->keyBy('indicator_id');

        $answerRows = $visit->answers->map(function ($answer) use ($computed) {
            $row = $computed->get($answer->indicator_id);

            return [
                'indicator_id' => (int) $answer->indicator_id,
                'option_id' => $answer->option_id,
                'score' => $answer->score,
                'value_number' => $answer->value_number,
                'value_text' => $answer->value_text,
                'value_long_text' => $answer->value_long_text,
                'value_date' => $answer->value_date?->toDateString(),
                'value_json' => $answer->value_json,
                'is_na' => (bool) $answer->is_na,
                'comment' => $answer->comment,
                'evidence_note' => $answer->evidence_note,
                'computed_score' => $row['computed_score'] ?? null,
                'answered_at' => $answer->answered_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'visit' => [
                ...$this->list($visit->loadMissing(['supervisor', 'supervisee', 'instrumentVersion.instrument'])),
                'pre_conference_at' => $visit->pre_conference_at?->toIso8601String(),
                'post_conference_at' => $visit->post_conference_at?->toIso8601String(),
                'summary' => $visit->summary,
                'strengths' => $visit->strengths,
                'improvements' => $visit->improvements,
                'revision' => (int) $visit->revision,
                'snapshot_checksum' => $visit->snapshot['checksum'] ?? null,
                'frozen_at' => $visit->snapshot['frozen_at'] ?? null,
            ],
            'instrument' => $version?->instrument === null ? null : [
                'id' => (int) $version->instrument->id,
                'code' => $version->instrument->code,
                'name' => $version->instrument->name,
                'description' => $version->instrument->description,
                'category' => $version->instrument->category->value,
                'category_label' => $version->instrument->category->label(),
                'target_type' => $version->instrument->target_type->value,
                'target_type_label' => $version->instrument->target_type->label(),
                'version' => (int) $version->version,
                'scoring_mode' => $version->scoring_mode->value,
                'scoring_mode_label' => $version->scoring_mode->label(),
                'max_score' => $version->max_score,
                'requires_action_plan' => (bool) $version->requires_action_plan,
                'grade_scale' => $version->gradeScale === null ? null : [
                    'code' => $version->gradeScale->code,
                    'name' => $version->gradeScale->name,
                    'scale_type' => $version->gradeScale->scale_type,
                    'bands' => $version->gradeScale->bands,
                ],
            ],
            'sections' => $sections,
            'answers' => $answerRows,
            'summary' => $preview['summary'],
            'band' => $preview['band'],
            'checklist' => $canFinalize || $visit->isFinalized() ? $this->finalization->checklist($visit) : [],
            'conferences' => $visit->conferences->mapWithKeys(fn ($conference) => [
                $conference->kind => [
                    'kind' => $conference->kind,
                    'held_at' => $conference->held_at?->toIso8601String(),
                    'location' => $conference->location,
                    'participants' => $conference->participants,
                    'answers' => $conference->answers,
                    'agreements' => $conference->agreements,
                    'reflection' => $conference->reflection,
                    'next_focus' => $conference->next_focus,
                ],
            ])->all(),
            'findings' => $visit->findings->map(fn ($finding) => [
                'id' => (int) $finding->id,
                'component' => $finding->component,
                'priority' => $finding->priority,
                'indicator_id' => $finding->indicator_id,
                'strength' => $finding->strength,
                'weakness' => $finding->weakness,
                'problem' => $finding->problem,
                'cause' => $finding->cause,
                'recommendation' => $finding->recommendation,
            ])->values()->all(),
            'evidence' => $visit->evidence->map(fn ($item) => [
                'id' => (int) $item->id,
                'visit_answer_id' => $item->visit_answer_id,
                'kind' => $item->kind->value,
                'kind_label' => $item->kind->label(),
                'url' => $item->url,
                'path' => $item->path,
                'caption' => $item->caption,
                'visibility' => $item->visibility->value,
                'created_at' => $item->created_at?->toIso8601String(),
            ])->values()->all(),
            'acknowledgment' => $this->acknowledgment($visit, $canViewAll),
            'disputes' => $visit->disputes->map(fn ($dispute) => [
                'id' => (int) $dispute->id,
                'status' => $dispute->status,
                'reason' => $dispute->reason,
                'proposed_scores' => $dispute->proposed_scores,
                'attachments' => $dispute->attachments,
                'decision_note' => $dispute->decision_note,
                'decided_at' => $dispute->decided_at?->toIso8601String(),
                'created_at' => $dispute->created_at?->toIso8601String(),
            ])->values()->all(),
            'history' => $visit->revisions->map(fn ($revision) => [
                'revision' => (int) $revision->revision,
                'action' => $revision->action,
                'reason' => $revision->reason,
                'before' => $revision->before,
                'after' => $revision->after,
                'at' => $revision->created_at?->toIso8601String(),
                'actor' => $this->userName($revision->actor_id),
            ])->values()->all(),
            'abilities' => [
                'execute' => $canExecute,
                'finalize' => $canFinalize,
                'acknowledge' => $this->access->employeeIdFor($viewer) === (int) $visit->supervisee_employee_id,
                'resolve_dispute' => $viewer->can('supervisi.dispute.resolve'),
                'reopen' => $viewer->can('supervisi.visit.reopen') && $visit->isFinalized(),
                'view_all' => $canViewAll,
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    protected function acknowledgment(SupVisit $visit, bool $canViewAll): ?array
    {
        $ack = $visit->acknowledgments->first();

        if ($ack === null) {
            return null;
        }

        return [
            'employee_id' => (int) $ack->employee_id,
            'acknowledged_at' => $ack->acknowledged_at?->toIso8601String(),
            'signature_hash' => $canViewAll ? $ack->signature_hash : null,
            'note' => $ack->note,
            'is_pending' => $ack->acknowledged_at === null,
        ];
    }

    /** @return array<string,mixed>|null */
    protected function employee(?\App\Models\Employee $employee): ?array
    {
        if ($employee === null) {
            return null;
        }

        return [
            'id' => (int) $employee->id,
            'full_name' => $employee->full_name,
            'nip' => $employee->nip,
            'position' => $employee->position,
            'employee_type' => $employee->employee_type,
        ];
    }

    protected function userName(?int $userId): ?string
    {
        return $userId === null ? null : User::query()->whereKey($userId)->value('name');
    }
}
