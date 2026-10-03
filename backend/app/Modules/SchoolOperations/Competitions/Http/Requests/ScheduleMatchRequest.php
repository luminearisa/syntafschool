<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['scheduled_at' => ['required', 'date'], 'scheduled_end_at' => ['nullable', 'date', 'after:scheduled_at'], 'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'], 'venue_name' => ['nullable', 'string', 'max:150'], 'room_id' => ['nullable', 'integer'], 'judge_ids' => ['nullable', 'array'], 'judge_ids.*' => ['integer', 'exists:competition_judges,id']];
    }
}
