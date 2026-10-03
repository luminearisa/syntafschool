<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AwardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['category_id' => ['nullable', 'integer', 'exists:competition_categories,id'], 'code' => ['nullable', 'string', 'max:60'], 'name' => ['required', 'string', 'max:200'], 'award_type' => ['nullable', 'string', 'max:40'], 'award_level' => ['nullable', 'string', 'max:30'], 'criteria_type' => ['nullable', Rule::in(['RANKING', 'AUTOMATIC', 'JUDGE_SELECTED', 'COMMITTEE_SELECTED', 'MANUAL'])], 'criteria_definition' => ['nullable', 'array'], 'position_from' => ['nullable', 'integer', 'min:1'], 'position_to' => ['nullable', 'integer', 'min:1'], 'quantity' => ['nullable', 'integer', 'min:1'], 'medal_type' => ['nullable', 'string', 'max:30'], 'description' => ['nullable', 'string', 'max:5000'], 'certificate_template_code' => ['nullable', 'string', 'max:60']];
    }
}
