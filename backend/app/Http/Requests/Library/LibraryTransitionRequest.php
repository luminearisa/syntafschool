<?php

namespace App\Http\Requests\Library;

use App\Models\Library;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Perpindahan status perpustakaan (draft → active → inactive → archived).
 */
class LibraryTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.implode(',', Library::STATUSES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['status' => 'status', 'reason' => 'alasan'];
    }
}