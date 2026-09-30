<?php

namespace App\Modules\CBT\Http\Requests;

use App\Modules\CBT\Domain\Enums\Difficulty;
use App\Modules\CBT\Domain\Enums\IncidentType;
use App\Modules\CBT\Domain\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_id' => ['sometimes', 'nullable', 'integer', 'exists:subjects,id'],
            'question_type' => ['sometimes', Rule::in(QuestionType::all())],
            'difficulty' => ['sometimes', Rule::in(Difficulty::all())],
            'topic' => ['sometimes', 'nullable', 'string', 'max:120'],
            'bloom_level' => ['sometimes', 'nullable', 'string', 'max:30'],
            'stem' => ['sometimes', 'string', 'max:20000'],
            'explanation' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'points' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'payload' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'array'],
            'options.*.option_key' => ['required_with:options', 'string', 'max:8'],
            'options.*.text' => ['required_with:options', 'string', 'max:5000'],
            'options.*.is_correct' => ['nullable', 'boolean'],
            'options.*.match_key' => ['nullable', 'string', 'max:8'],
        ];
    }
}
