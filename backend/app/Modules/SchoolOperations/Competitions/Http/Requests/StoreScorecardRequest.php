<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreScorecardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['participant_id' => ['required', 'integer', 'exists:competition_participants,id'], 'rubric_id' => ['nullable', 'integer', 'exists:competition_rubrics,id'], 'judge_id' => ['nullable', 'integer', 'exists:competition_judges,id'], 'scores' => ['required', 'array', 'min:1'], 'scores.*.criterion_id' => ['required', 'integer', 'exists:competition_rubric_criteria,id'], 'scores.*.score' => ['required', 'numeric'], 'scores.*.comment' => ['nullable', 'string', 'max:2000'], 'scores.*.idempotency_key' => ['nullable', 'string', 'max:120'], 'comment' => ['nullable', 'string', 'max:4000'], 'idempotency_key' => ['nullable', 'string', 'max:120'], 'device_id' => ['nullable', 'string', 'max:120'], 'offline_created_at' => ['nullable', 'date']];
    }
}
