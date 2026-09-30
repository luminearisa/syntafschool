<?php

namespace App\Modules\CBT\Http\Requests;

use App\Modules\CBT\Domain\Enums\Difficulty;
use App\Modules\CBT\Domain\Enums\IncidentType;
use App\Modules\CBT\Domain\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GradeEssayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'awarded' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
