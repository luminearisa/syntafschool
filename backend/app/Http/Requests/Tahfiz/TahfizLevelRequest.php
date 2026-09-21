<?php

namespace App\Http\Requests\Tahfiz;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Data level Tahfiz.
 */
class TahfizLevelRequest extends FormRequest
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
            'program_id' => ['nullable', 'integer', 'exists:tahfiz_programs,id'],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:30'],
            'order' => ['nullable', 'integer', 'min:0'],
            'juz_start' => ['nullable', 'integer', 'min:1', 'max:30'],
            'juz_end' => ['nullable', 'integer', 'min:1', 'max:30'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,archived'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'juz_start' => 'juz awal',
            'juz_end' => 'juz akhir',
        ];
    }

    /**
     * Urutan & juz harus konsisten.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('juz_start') && $this->filled('juz_end')) {
            $start = (int) $this->input('juz_start');
            $end = (int) $this->input('juz_end');

            if ($start > $end) {
                $this->merge(['juz_start' => $end, 'juz_end' => $start]);
            }
        }
    }
}
