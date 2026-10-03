<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignJudgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['judge_id' => ['required', 'integer', 'exists:competition_judges,id'], 'category_id' => ['nullable', 'integer', 'exists:competition_categories,id'], 'round_id' => ['nullable', 'integer', 'exists:competition_rounds,id'], 'session_id' => ['nullable', 'integer', 'exists:competition_sessions,id'], 'match_id' => ['nullable', 'integer', 'exists:competition_matches,id'], 'assignment_role' => ['nullable', Rule::in(['PRIMARY', 'JUDGE', 'ASSISTANT_JUDGE', 'HEAD_JUDGE', 'TECHNICAL_JUDGE', 'TIMEKEEPER', 'REFEREE', 'JURY'])], 'is_head_judge' => ['sometimes', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000']];
    }
}
