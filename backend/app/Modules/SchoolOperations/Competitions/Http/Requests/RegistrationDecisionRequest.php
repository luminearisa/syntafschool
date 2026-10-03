<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrationDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['decision' => ['required', Rule::in(['APPROVED', 'REJECTED', 'NEEDS_REVISION'])], 'reason' => ['nullable', 'string', 'max:2000'], 'override' => ['sometimes', 'boolean']];
    }
}
