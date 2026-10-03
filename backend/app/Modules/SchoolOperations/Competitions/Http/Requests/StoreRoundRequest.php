<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['category_id' => ['required', 'integer', 'exists:competition_categories,id'], 'name' => ['required', 'string', 'max:200'], 'code' => ['nullable', 'string', 'max:60'], 'sequence' => ['nullable', 'integer', 'min:1'], 'round_type' => ['nullable', 'string', 'max:40'], 'format' => ['nullable', Rule::in(['SINGLE', 'MULTI_ROUND', 'KNOCKOUT', 'ROUND_ROBIN', 'SWISS', 'GROUP', 'GROUP_KNOCKOUT', 'QUALIFICATION', 'FINAL', 'SUBMISSION_JUDGING', 'TIME_BASED', 'POINT_BASED'])], 'scoring_method' => ['nullable', 'string', 'max:40'], 'advancement_rule' => ['nullable', 'string', 'max:40'], 'qualification_count' => ['nullable', 'integer', 'min:1'], 'qualification_threshold' => ['nullable', 'numeric'], 'bracket_size' => ['nullable', 'integer', 'min:2'], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'], 'is_final' => ['sometimes', 'boolean'], 'settings' => ['nullable', 'array']];
    }
}
