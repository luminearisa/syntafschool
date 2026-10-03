<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateFixturesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['format' => ['nullable', Rule::in(['KNOCKOUT', 'BRACKET', 'SINGLE_ELIMINATION', 'ROUND_ROBIN', 'GROUP', 'SWISS'])], 'bracket_size' => ['nullable', 'integer', 'min:2'], 'groups' => ['nullable', 'integer', 'min:1'], 'home_away' => ['sometimes', 'boolean'], 'qualification_count' => ['nullable', 'integer', 'min:1'], 'group_name' => ['nullable', 'string', 'max:120']];
    }
}
