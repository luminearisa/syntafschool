<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['participant_id' => ['nullable', 'integer', 'exists:competition_participants,id'], 'team_id' => ['nullable', 'integer', 'exists:competition_teams,id'], 'session_id' => ['nullable', 'integer', 'exists:competition_sessions,id'], 'match_id' => ['nullable', 'integer', 'exists:competition_matches,id'], 'checkin_method' => ['nullable', Rule::in(['QR', 'BARCODE', 'RFID', 'NFC', 'MANUAL'])], 'identity_verification_method' => ['nullable', Rule::in(['STUDENT_CARD', 'QR', 'RFID', 'NFC', 'MANUAL'])], 'identity_reference' => ['nullable', 'string', 'max:120'], 'idempotency_key' => ['nullable', 'string', 'max:120'], 'device_id' => ['nullable', 'string', 'max:120'], 'location' => ['nullable', 'string', 'max:150'], 'notes' => ['nullable', 'string', 'max:2000']];
    }
}
