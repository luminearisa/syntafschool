<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'competition_division_id' => ['nullable', 'integer', 'exists:competition_divisions,id'],
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:220'],
            'description' => ['nullable', 'string', 'max:10000'],
            'gender_restriction' => ['nullable', Rule::in(['ANY', 'MALE', 'FEMALE'])],
            'min_grade_level' => ['nullable', 'string', 'max:20'],
            'max_grade_level' => ['nullable', 'string', 'max:20'],
            'program_restriction' => ['nullable', 'string', 'max:120'],
            'min_age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'max_age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'participant_mode' => ['nullable', Rule::in(['INDIVIDUAL', 'TEAM', 'MIXED', 'ORGANIZATION'])],
            'min_team_size' => ['nullable', 'integer', 'min:1'],
            'max_team_size' => ['nullable', 'integer', 'min:1'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'min_participants' => ['nullable', 'integer', 'min:1'],
            'registration_fee' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'fee_mode' => ['nullable', Rule::in(['PER_PARTICIPANT', 'PER_TEAM', 'CATEGORY'])],
            'scoring_model' => ['nullable', 'string', 'max:40'],
            'ranking_model' => ['nullable', Rule::in(['HIGHER_BETTER', 'LOWER_BETTER', 'PLACEMENT'])],
            'aggregation_method' => ['nullable', Rule::in(['AVERAGE', 'WEIGHTED_AVERAGE', 'MEDIAN', 'DROP_HIGHEST', 'DROP_LOWEST', 'HEAD_JUDGE_OVERRIDE', 'CONSENSUS'])],
            'tie_break_strategy' => ['nullable', 'array'],
            'award_configuration' => ['nullable', 'array'],
            'round_format' => ['nullable', 'string', 'max:40'],
            'selection_method' => ['nullable', 'string', 'max:30'],
            'requires_submission' => ['sometimes', 'boolean'],
            'requires_judge' => ['sometimes', 'boolean'],
            'requires_documents' => ['sometimes', 'boolean'],
            'requires_parent_consent' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'settings' => ['nullable', 'array'],
        ];
    }
}
