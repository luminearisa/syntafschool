<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScoreAmendmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000'], 'scores' => ['required', 'array', 'min:1'], 'scores.*.criterion_id' => ['required', 'integer', 'exists:competition_rubric_criteria,id'], 'scores.*.score' => ['required', 'numeric'], 'scores.*.comment' => ['nullable', 'string', 'max:2000'], 'comment' => ['nullable', 'string', 'max:4000']];
    }
}
