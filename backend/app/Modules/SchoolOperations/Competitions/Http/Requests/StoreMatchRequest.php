<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['home_participant_id' => ['nullable', 'integer', 'exists:competition_participants,id'], 'away_participant_id' => ['nullable', 'integer', 'exists:competition_participants,id'], 'group_id' => ['nullable', 'integer', 'exists:competition_groups,id'], 'session_id' => ['nullable', 'integer', 'exists:competition_sessions,id'], 'match_number' => ['nullable', 'integer', 'min:1'], 'name' => ['nullable', 'string', 'max:200'], 'venue_name' => ['nullable', 'string', 'max:150'], 'room_id' => ['nullable', 'integer'], 'scheduled_at' => ['nullable', 'date'], 'scheduled_end_at' => ['nullable', 'date', 'after:scheduled_at'], 'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'], 'notes' => ['nullable', 'string', 'max:4000']];
    }
}
