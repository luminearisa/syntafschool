<?php

namespace App\Modules\CBT\Http\Requests;

use App\Modules\CBT\Domain\Enums\Difficulty;
use App\Modules\CBT\Domain\Enums\IncidentType;
use App\Modules\CBT\Domain\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'code' => ['nullable', 'string', 'max:40', 'unique:cbt_questions,code'],
            'question_type' => ['required', Rule::in(QuestionType::all())],
            'difficulty' => ['nullable', Rule::in(Difficulty::all())],
            'topic' => ['nullable', 'string', 'max:120'],
            'bloom_level' => ['nullable', 'string', 'max:30'],
            'stem' => ['required', 'string', 'max:20000'],
            'explanation' => ['nullable', 'string', 'max:20000'],
            'points' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'payload' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'options' => ['nullable', 'array'],
            'options.*.option_key' => ['required_with:options', 'string', 'max:8'],
            'options.*.text' => ['required_with:options', 'string', 'max:5000'],
            'options.*.is_correct' => ['nullable', 'boolean'],
            'options.*.match_key' => ['nullable', 'string', 'max:8'],
            'options.*.position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
