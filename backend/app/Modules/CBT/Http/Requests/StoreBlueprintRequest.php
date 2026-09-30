<?php

namespace App\Modules\CBT\Http\Requests;

use App\Modules\CBT\Domain\Enums\Difficulty;
use App\Modules\CBT\Domain\Enums\IncidentType;
use App\Modules\CBT\Domain\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBlueprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40', 'unique:cbt_blueprints,code'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'question_count' => ['nullable', 'integer', 'min:0', 'max:500'],
            'max_score' => ['nullable', 'numeric', 'min:1'],
            'passing_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'shuffle_questions' => ['nullable', 'boolean'],
            'shuffle_options' => ['nullable', 'boolean'],
            'negative_marking' => ['nullable', 'boolean'],
            'negative_fraction' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'attempts_allowed' => ['nullable', 'integer', 'min:1', 'max:10'],
            'rules' => ['nullable', 'array'],
            'rules.*.topic' => ['nullable', 'string', 'max:120'],
            'rules.*.difficulty' => ['nullable', Rule::in(Difficulty::all())],
            'rules.*.question_type' => ['nullable', Rule::in(QuestionType::all())],
            'rules.*.count' => ['required_with:rules', 'integer', 'min:1', 'max:200'],
            'rules.*.points' => ['nullable', 'numeric', 'min:0'],
            'items' => ['nullable', 'array'],
            'items.*.question_id' => ['required_with:items', 'integer', 'exists:cbt_questions,id'],
            'items.*.points' => ['nullable', 'numeric', 'min:0'],
            'items.*.required' => ['nullable', 'boolean'],
        ];
    }
}
