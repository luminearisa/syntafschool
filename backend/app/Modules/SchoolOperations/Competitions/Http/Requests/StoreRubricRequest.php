<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRubricRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['category_id' => ['nullable', 'integer', 'exists:competition_categories,id'], 'round_id' => ['nullable', 'integer', 'exists:competition_rounds,id'], 'code' => ['nullable', 'string', 'max:60'], 'name' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:5000'], 'scoring_type' => ['nullable', Rule::in(['NUMERIC', 'WEIGHTED', 'POINTS', 'TIME', 'DISTANCE', 'PLACEMENT', 'BEST_OF_N'])], 'application_scope' => ['nullable', 'string', 'max:30'], 'is_default' => ['sometimes', 'boolean'], 'settings' => ['nullable', 'array'], 'criteria' => ['required', 'array', 'min:1'], 'criteria.*.code' => ['nullable', 'string', 'max:60'], 'criteria.*.name' => ['required', 'string', 'max:200'], 'criteria.*.description' => ['nullable', 'string', 'max:2000'], 'criteria.*.weight' => ['required', 'numeric', 'min:0'], 'criteria.*.min_score' => ['nullable', 'numeric'], 'criteria.*.max_score' => ['nullable', 'numeric'], 'criteria.*.step' => ['nullable', 'numeric', 'min:0'], 'criteria.*.is_required' => ['sometimes', 'boolean'], 'criteria.*.required_comment' => ['sometimes', 'boolean'], 'criteria.*.sort_order' => ['nullable', 'integer', 'min:0']];
    }
}
